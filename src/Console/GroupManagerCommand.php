<?php

namespace CryptoForex\GroupManager\Console;

use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

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
    protected function fire()
    {
        // Get options
        $isStatsOnly = $this->option('stats');
        $isDryRun = $this->option('dry-run');
        $isVerbose = $this->option('verbose');

        if ($isStatsOnly) {
            $this->showStatistics();
            return 0;
        }

        $this->info('🚀 GROUP MANAGER - ' . ($isDryRun ? 'DRY RUN MODE' : 'Processing Changes'));
        
        if ($isDryRun) {
            $this->comment('🧪 DRY RUN MODE - No changes will be made');
        }

        try {
            $promotionCandidates = $this->findPromotionCandidates();
            $demotionCandidates = $this->findDemotionCandidates();

            $totalChanges = count($promotionCandidates) + count($demotionCandidates);

            if ($totalChanges === 0) {
                $this->info('✅ No changes needed - all users are in correct groups!');
                return 0;
            }

            if ($isDryRun || $isVerbose) {
                $this->previewChanges($promotionCandidates, $demotionCandidates, $isVerbose);
            }

            if (!$isDryRun) {
                $this->applyChanges($promotionCandidates, $demotionCandidates);
                $this->info("✅ Successfully processed {$totalChanges} user group changes!");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Find users eligible for promotion to VIP group
     */
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

    /**
     * Find VIP users who should be demoted
     */
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

    /**
     * Display current statistics
     */
    private function showStatistics()
    {
        $totalUsers = User::count();
        $vipUsers = $this->db->table('group_user')
            ->where('group_id', $this->promotionGroupId)
            ->count();

        $promotionCandidates = count($this->findPromotionCandidates());
        $demotionCandidates = count($this->findDemotionCandidates());

        // Users with money >= promotion amount
        $richUsers = User::where('money', '>=', $this->promotionAmount)->count();
        $poorUsers = User::where('money', '<', $this->demotionAmount)->count();
        
        // Average balance calculation
        $avgBalance = User::avg('money') ?? 0;

        $this->info('📊 GROUP MANAGER STATISTICS');
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("👥 Total Users: {$totalUsers}");
        $this->line("⭐ Current VIP Users: {$vipUsers}");
        $this->line("💰 Average Balance: $" . number_format($avgBalance, 2));
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("📈 Promotion Threshold: \${$this->promotionAmount}");
        $this->line("📉 Demotion Threshold: \${$this->demotionAmount}");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("💎 Users with ≥\${$this->promotionAmount}: {$richUsers}");
        $this->line("📉 Users with <\${$this->demotionAmount}: {$poorUsers}");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("🔼 Users Eligible for VIP Promotion: {$promotionCandidates}");
        $this->comment("🔽 VIP Users to be Demoted: {$demotionCandidates}");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        
        if ($promotionCandidates > 0 || $demotionCandidates > 0) {
            $this->line("");
            $this->comment("💡 Run with --dry-run to see what would change");
            $this->comment("💡 Run without --dry-run to apply changes");
        } else {
            $this->info("✅ All users are in their correct groups!");
        }
    }

    /**
     * Preview changes that would be made
     */
    private function previewChanges($promotions, $demotions, $verbose = false)
    {
        $this->line("");
        
        if (count($promotions) > 0) {
            $this->info('🔼 PROMOTIONS TO VIP GROUP:');
            
            if ($verbose) {
                foreach ($promotions as $user) {
                    $money = $user->money ? number_format($user->money, 2) : '0.00';
                    $this->line("   • {$user->username} (\${$money}) → Adding to VIP Group");
                }
            } else {
                $this->line("   • " . count($promotions) . " users will be added to VIP");
            }
            $this->line("");
        }

        if (count($demotions) > 0) {
            $this->comment('🔽 DEMOTIONS FROM VIP GROUP:');
            
            if ($verbose) {
                foreach ($demotions as $user) {
                    $money = $user->money ? number_format($user->money, 2) : '0.00';
                    $this->line("   • {$user->username} (\${$money}) → Removing from VIP Group");
                }
            } else {
                $this->line("   • " . count($demotions) . " users will be removed from VIP");
            }
            $this->line("");
        }
    }

    /**
     * Apply the group changes to database
     */
    private function applyChanges($promotions, $demotions)
    {
        $promotionCount = 0;
        $demotionCount = 0;

        $this->db->transaction(function() use ($promotions, $demotions, &$promotionCount, &$demotionCount) {
            
            // Apply promotions
            foreach ($promotions as $user) {
                $exists = $this->db->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $this->promotionGroupId)
                    ->exists();
                
                if (!$exists) {
                    $this->db->table('group_user')->insert([
                        'user_id' => $user->id,
                        'group_id' => $this->promotionGroupId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    $promotionCount++;
                    $money = $user->money ? number_format($user->money, 2) : '0.00';
                    $this->info("✅ {$user->username} (\${$money}) promoted to VIP Group");
                }
            }

            // Apply demotions  
            foreach ($demotions as $user) {
                $deleted = $this->db->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $this->promotionGroupId)
                    ->delete();
                    
                if ($deleted > 0) {
                    $demotionCount++;
                    $money = $user->money ? number_format($user->money, 2) : '0.00';
                    $this->comment("✅ {$user->username} (\${$money}) removed from VIP Group");
                }
            }
        });

        // Summary
        $this->line("");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("📊 SUMMARY:");
        $this->line("🔼 Users promoted to VIP: {$promotionCount}");
        $this->line("🔽 Users demoted from VIP: {$demotionCount}");
        $this->line("📈 Total changes applied: " . ($promotionCount + $demotionCount));
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }
}
