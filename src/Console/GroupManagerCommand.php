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
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed output');
    }

    /**
     * {@inheritdoc}
     */
    protected function fire()
    {
        // Get options
        $isStatsOnly = $this->option('stats');
        $isDryRun = $this->option('dry-run');
        $isVerbose = $this->option('detailed');

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
                $this->previewChanges($promotionCandidates, $demotionCandidates);
            }

            if (!$isDryRun) {
                $this->applyChanges($promotionCandidates, $demotionCandidates);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }

    private function findPromotionCandidates()
    {
        return $this->db->table('users')
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
        return $this->db->table('users')
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
        $this->line("");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("📊 GROUP MANAGER STATISTICS");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // Current group statistics
        $vipUsers = $this->db->table('users')
            ->join('group_user', 'users.id', '=', 'group_user.user_id')
            ->where('group_user.group_id', $this->promotionGroupId)
            ->count();

        $totalUsers = $this->db->table('users')->count();
        
        $this->line("👥 Total Users: {$totalUsers}");
        $this->line("👑 VIP Users: {$vipUsers}");
        $this->line("📊 VIP Rate: " . round(($vipUsers / max($totalUsers, 1)) * 100, 2) . "%");
        
        // Amount statistics
        $avgBalance = $this->db->table('users')
            ->whereNotNull('money')
            ->avg('money');
            
        $maxBalance = $this->db->table('users')
            ->whereNotNull('money')
            ->max('money');

        $this->line("");
        $this->line("💰 Average Balance: $" . number_format($avgBalance ?? 0, 2));
        $this->line("🏆 Highest Balance: $" . number_format($maxBalance ?? 0, 2));
        $this->line("");
        $this->line("🎯 VIP Threshold: $" . number_format($this->promotionAmount, 2));
        $this->line("⚠️  Demotion Threshold: $" . number_format($this->demotionAmount, 2));

        // Pending changes
        $promotionCandidates = $this->findPromotionCandidates();
        $demotionCandidates = $this->findDemotionCandidates();

        $this->line("");
        $this->line("🔄 PENDING CHANGES:");
        $this->line("🔼 Users eligible for VIP: " . count($promotionCandidates));
        $this->line("🔽 VIP users below threshold: " . count($demotionCandidates));
        
        if (count($promotionCandidates) > 0 || count($demotionCandidates) > 0) {
            $this->comment("");
            $this->comment("💡 Run 'php flarum group:manage' to apply changes");
            $this->comment("💡 Run 'php flarum group:manage --dry-run --detailed' to preview changes");
        }

        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    private function previewChanges($promotions, $demotions)
    {
        $this->line("");
        $this->line("🔍 PREVIEW OF CHANGES:");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━");

        if (count($promotions) > 0) {
            $this->line("");
            $this->info("🔼 PROMOTIONS TO VIP (" . count($promotions) . " users):");
            foreach ($promotions as $user) {
                $money = $user->money ? number_format($user->money, 2) : '0.00';
                $this->line("   → {$user->username} (\${$money})");
            }
        }

        if (count($demotions) > 0) {
            $this->line("");
            $this->comment("🔽 DEMOTIONS FROM VIP (" . count($demotions) . " users):");
            foreach ($demotions as $user) {
                $money = $user->money ? number_format($user->money, 2) : '0.00';
                $this->line("   → {$user->username} (\${$money})");
            }
        }

        $this->line("━━━━━━━━━━━━━━━━━━━━━━");
    }

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
