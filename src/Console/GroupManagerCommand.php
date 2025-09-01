<?php

namespace CryptoForex\GroupManager\Console;

use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GroupManagerCommand extends AbstractCommand
{
    /**
     * @var ConnectionInterface
     */
    private $db;
    
    private $promotionGroupId = 5;    // VIP Group ID
    private $demotionGroupId = 3;     // Basic Group ID  
    private $promotionAmount = 500;   // $500 minimum for VIP
    private $demotionAmount = 100;    // Below $100 = lose VIP

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('group:manage')
            ->setDescription('Manage user groups based on balance')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show statistics only')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be changed without making changes')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Show detailed output');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get options
        $isStatsOnly = $input->getOption('stats');
        $isDryRun = $input->getOption('dry-run');
        $isVerbose = $input->getOption('verbose');

        if ($isStatsOnly) {
            $this->showStatistics($output);
            return 0;
        }

        $output->writeln('<info>🚀 GROUP MANAGER - ' . ($isDryRun ? 'DRY RUN MODE' : 'Processing Changes') . '</info>');
        
        if ($isDryRun) {
            $output->writeln('<comment>🧪 DRY RUN MODE - No changes will be made</comment>');
        }

        try {
            $promotionCandidates = $this->findPromotionCandidates();
            $demotionCandidates = $this->findDemotionCandidates();

            $totalChanges = count($promotionCandidates) + count($demotionCandidates);

            if ($totalChanges === 0) {
                $output->writeln('<info>✅ No changes needed - all users are in correct groups!</info>');
                return 0;
            }

            if ($isDryRun || $isVerbose) {
                $this->previewChanges($promotionCandidates, $demotionCandidates, $output, $isVerbose);
            }

            if (!$isDryRun) {
                $this->applyChanges($promotionCandidates, $demotionCandidates, $output);
                $output->writeln("<info>✅ Successfully processed {$totalChanges} user group changes!</info>");
            }

            return 0;

        } catch (\Exception $e) {
            $output->writeln('<error>❌ Error: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    private function findPromotionCandidates()
    {
        return User::where('money', '>=', $this->promotionAmount)
            ->whereNotExists(function ($query) {
                $query->select('id')
                    ->from('group_user')
                    ->whereRaw('group_user.user_id = users.id')
                    ->where('group_id', $this->promotionGroupId);
            })
            ->get();
    }

    private function findDemotionCandidates()
    {
        return User::where('money', '<', $this->demotionAmount)
            ->whereExists(function ($query) {
                $query->select('id')
                    ->from('group_user')
                    ->whereRaw('group_user.user_id = users.id')
                    ->where('group_id', $this->promotionGroupId);
            })
            ->get();
    }

    private function showStatistics(OutputInterface $output)
    {
        $totalUsers = User::count();
        $vipUsers = $this->db->table('group_user')
            ->where('group_id', $this->promotionGroupId)
            ->count();

        $promotionCandidates = count($this->findPromotionCandidates());
        $demotionCandidates = count($this->findDemotionCandidates());

        $output->writeln('<info>📊 GROUP MANAGER STATISTICS</info>');
        $output->writeln("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $output->writeln("👥 Total Users: {$totalUsers}");
        $output->writeln("⭐ Current VIP Users: {$vipUsers}");
        $output->writeln("💰 Promotion Threshold: \${$this->promotionAmount}");
        $output->writeln("📉 Demotion Threshold: \${$this->demotionAmount}");
        $output->writeln("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $output->writeln("🔼 Users Eligible for VIP: {$promotionCandidates}");
        $output->writeln("🔽 Users to Remove from VIP: {$demotionCandidates}");
        $output->writeln("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    private function previewChanges($promotions, $demotions, OutputInterface $output, $verbose = false)
    {
        if (count($promotions) > 0) {
            $output->writeln('<info>🔼 PROMOTIONS TO VIP GROUP:</info>');
            
            if ($verbose) {
                foreach ($promotions as $user) {
                    $money = $user->money ? number_format($user->money, 2) : '0.00';
                    $output->writeln("   • {$user->username} (\${$money}) → Adding to VIP Group");
                }
            } else {
                $output->writeln("   • " . count($promotions) . " users will be added to VIP");
            }
        }

        if (count($demotions) > 0) {
            $output->writeln('<comment>🔽 DEMOTIONS FROM VIP GROUP:</comment>');
            
            if ($verbose) {
                foreach ($demotions as $user) {
                    $money = $user->money ? number_format($user->money, 2) : '0.00';
                    $output->writeln("   • {$user->username} (\${$money}) → Removing from VIP Group");
                }
            } else {
                $output->writeln("   • " . count($demotions) . " users will be removed from VIP");
            }
        }
    }

    private function applyChanges($promotions, $demotions, OutputInterface $output)
    {
        $this->db->transaction(function() use ($promotions, $demotions, $output) {
            // Apply promotions
            foreach ($promotions as $user) {
                $exists = $this->db->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $this->promotionGroupId)
                    ->exists();
                
                if (!$exists) {
                    $this->db->table('group_user')->insert([
                        'user_id' => $user->id,
                        'group_id' => $this->promotionGroupId
                    ]);
                    
                    $output->writeln("✅ {$user->username} promoted to VIP Group");
                }
            }

            // Apply demotions
            foreach ($demotions as $user) {
                $deleted = $this->db->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $this->promotionGroupId)
                    ->delete();
                    
                if ($deleted > 0) {
                    $output->writeln("✅ {$user->username} removed from VIP Group");
                }
            }
        });
    }
}
