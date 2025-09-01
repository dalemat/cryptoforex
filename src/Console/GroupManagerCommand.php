<?php

namespace CryptoForex\GroupManager\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

class GroupManagerCommand extends AbstractCommand
{
    /**
     * @var ConnectionInterface
     */
    protected $database;
    
    private $promotionGroupId = 5;    // VIP Group ID
    private $demotionGroupId = 3;     // Basic Group ID  
    private $promotionAmount = 500;   // $500 minimum for VIP
    private $demotionAmount = 100;    // Below $100 = lose VIP

    public function __construct(ConnectionInterface $database)
    {
        $this->database = $database;
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
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed output');
    }

    /**
     * {@inheritdoc}
     */
    protected function fire()
    {
        $isStatsOnly = $this->input->getOption('stats');
        $isDryRun = $this->input->getOption('dry-run');
        $isDetailed = $this->input->getOption('detailed');

        if ($isStatsOnly) {
            $this->showStatistics();
            return 0;
        }

        $this->info('GROUP MANAGER - ' . ($isDryRun ? 'DRY RUN MODE' : 'Processing Changes'));
        
        if ($isDryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        try {
            $promotionCandidates = $this->findPromotionCandidates();
            $demotionCandidates = $this->findDemotionCandidates();

            $totalChanges = count($promotionCandidates) + count($demotionCandidates);

            if ($totalChanges === 0) {
                $this->info('No changes needed - all users are in correct groups!');
                return 0;
            }

            if ($isDryRun || $isDetailed) {
                $this->previewChanges($promotionCandidates, $demotionCandidates);
            }

            if (!$isDryRun) {
                $this->applyChanges($promotionCandidates, $demotionCandidates);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    private function findPromotionCandidates()
    {
        return $this->database->table('users')
            ->leftJoin('group_user', function($join) {
                $join->on('users.id', '=', 'group_user.user_id')
                     ->where('group_user.group_id', '=', $this->promotionGroupId);
            })
            ->where('users.money', '>=', $this->promotionAmount)
            ->whereNull('group_user.user_id')
            ->select('users.id', 'users.username', 'users.money')
            ->get();
    }

    private function findDemotionCandidates()
    {
        return $this->database->table('users')
            ->join('group_user', 'users.id', '=', 'group_user.user_id')
            ->where('group_user.group_id', $this->promotionGroupId)
            ->where(function($query) {
                $query->where('users.money', '<', $this->demotionAmount)
                      ->orWhereNull('users.money');
            })
            ->select('users.id', 'users.username', 'users.money')
            ->get();
    }

    private function showStatistics()
    {
        $this->info('=== GROUP MANAGER STATISTICS ===');

        // Current group statistics
        $vipUsers = $this->database->table('users')
            ->join('group_user', 'users.id', '=', 'group_user.user_id')
            ->where('group_user.group_id', $this->promotionGroupId)
            ->count();

        $totalUsers = $this->database->table('users')->count();
        
        $this->info("Total Users: {$totalUsers}");
        $this->info("VIP Users: {$vipUsers}");
        $this->info("VIP Rate: " . round(($vipUsers / max($totalUsers, 1)) * 100, 2) . "%");
        
        // Amount statistics
        $avgBalance = $this->database->table('users')
            ->whereNotNull('money')
            ->avg('money');
            
        $maxBalance = $this->database->table('users')
            ->whereNotNull('money')
            ->max('money');

        $this->info("Average Balance: $" . number_format($avgBalance ?? 0, 2));
        $this->info("Highest Balance: $" . number_format($maxBalance ?? 0, 2));
        $this->info("VIP Threshold: $" . number_format($this->promotionAmount, 2));
        $this->info("Demotion Threshold: $" . number_format($this->demotionAmount, 2));

        // Pending changes
        $promotionCandidates = $this->findPromotionCandidates();
        $demotionCandidates = $this->findDemotionCandidates();

        $this->info("=== PENDING CHANGES ===");
        $this->info("Users eligible for VIP: " . count($promotionCandidates));
        $this->info("VIP users below threshold: " . count($demotionCandidates));
        
        if (count($promotionCandidates) > 0 || count($demotionCandidates) > 0) {
            $this->info("Run 'php flarum group:manage' to apply changes");
            $this->info("Run 'php flarum group:manage --dry-run --detailed' to preview");
        }
    }

    private function previewChanges($promotions, $demotions)
    {
        $this->info("=== PREVIEW OF CHANGES ===");

        if (count($promotions) > 0) {
            $this->info("PROMOTIONS TO VIP (" . count($promotions) . " users):");
            foreach ($promotions as $user) {
                $money = $user->money ? number_format($user->money, 2) : '0.00';
                $this->info("  -> {$user->username} (\${$money})");
            }
        }

        if (count($demotions) > 0) {
            $this->info("DEMOTIONS FROM VIP (" . count($demotions) . " users):");
            foreach ($demotions as $user) {
                $money = $user->money ? number_format($user->money, 2) : '0.00';
                $this->info("  -> {$user->username} (\${$money})");
            }
        }
    }

    private function applyChanges($promotions, $demotions)
    {
        $promotionCount = 0;
        $demotionCount = 0;

        $this->database->transaction(function() use ($promotions, $demotions, &$promotionCount, &$demotionCount) {
            // Apply promotions
            foreach ($promotions as $user) {
                $exists = $this->database->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $this->promotionGroupId)
                    ->exists();
                
                if (!$exists) {
                    $this->database->table('group_user')->insert([
                        'user_id' => $user->id,
                        'group_id' => $this->promotionGroupId
                    ]);
                    
                    $promotionCount++;
                    $money = $user->money ? number_format($user->money, 2) : '0.00';
                    $this->info("PROMOTED: {$user->username} (\${$money}) to VIP Group");
                }
            }

            // Apply demotions  
            foreach ($demotions as $user) {
                $deleted = $this->database->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $this->promotionGroupId)
                    ->delete();
                    
                if ($deleted > 0) {
                    $demotionCount++;
                    $money = $user->money ? number_format($user->money, 2) : '0.00';
                    $this->info("DEMOTED: {$user->username} (\${$money}) from VIP Group");
                }
            }
        });

        // Summary
        $this->info("=== SUMMARY ===");
        $this->info("Users promoted to VIP: {$promotionCount}");
        $this->info("Users demoted from VIP: {$demotionCount}");
        $this->info("Total changes applied: " . ($promotionCount + $demotionCount));
    }
}
