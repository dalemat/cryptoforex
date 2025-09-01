<?php

namespace CryptoForex\GroupManager\Console;

use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

class GroupManagerCommand extends AbstractCommand
{
    protected $signature = 'group:manage {--stats : Show statistics only} {--dry-run : Show what would be changed without making changes} {--verbose : Show detailed output}';
    protected $description = 'Manage user groups based on balance';

    private $db;
    private $promotionGroupId = 5;    // VIP Group ID
    private $demotionGroupId = 3;     // Basic Group ID  
    private $promotionAmount = 500;   // $500 minimum for VIP
    private $demotionAmount = 100;    // Below $100 = lose VIP

    public function __construct(ConnectionInterface $db)
    {
        parent::__construct();
        $this->db = $db;
    }

    // ✅ FIXED: Correct method signature for Flarum
    protected function fire()
    {
        $isStatsOnly = $this->option('stats');
        $isDryRun = $this->option('dry-run');
        $isVerbose = $this->option('verbose');

        if ($isStatsOnly) {
            $this->showStatistics();
            return 0;
        }

        $this->info('🚀 GROUP MANAGER - ' . ($isDryRun ? 'DRY RUN MODE' : 'Processing Changes'));
        
        if ($isDryRun) {
            $this->warn('🧪 DRY RUN MODE - No changes will be made');
        }

        try {
            // Find users who should be promoted
            $promotionCandidates = $this->findPromotionCandidates();
            
            // Find users who should be demoted  
            $demotionCandidates = $this->findDemotionCandidates();

            $totalChanges = count($promotionCandidates) + count($demotionCandidates);

            if ($totalChanges === 0) {
                $this->info('✅ No changes needed - all users are in correct groups');
                return 0;
            }

            // Show what will be changed
            $this->showChanges($promotionCandidates, $demotionCandidates, $isVerbose);

            if (!$isDryRun) {
                // Apply changes
                $this->applyChanges($promotionCandidates, $demotionCandidates);
                $this->info("✅ Successfully processed {$totalChanges} changes");
            } else {
                $this->info("✅ Would process {$totalChanges} total changes");
            }

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function showStatistics()
    {
        $this->info('💰 Current Balance Thresholds:');
        $this->line("   Promotion: $" . number_format($this->promotionAmount, 2));  
        $this->line("   Demotion: $" . number_format($this->demotionAmount, 2));
        $this->line('');

        // Count current group members
        $vipCount = $this->db->table('group_user')
            ->where('group_id', $this->promotionGroupId)
            ->count();
            
        $basicCount = $this->db->table('group_user')
            ->where('group_id', $this->demotionGroupId)  
            ->count();

        // Count eligible for changes
        $promotionEligible = count($this->findPromotionCandidates());
        $demotionEligible = count($this->findDemotionCandidates());

        $this->info("👑 VIP Group Members: {$vipCount}");
        $this->info("👤 Basic Group Members: {$basicCount}");
        $this->info("⬆️  Eligible for Promotion: {$promotionEligible}");
        $this->info("⬇️  Eligible for Demotion: {$demotionEligible}");
    }

    private function findPromotionCandidates()
    {
        return $this->db->table('users')
            ->select('users.id', 'users.username', 'users.money')
            ->leftJoin('group_user', function($join) {
                $join->on('users.id', '=', 'group_user.user_id')
                     ->where('group_user.group_id', '=', $this->promotionGroupId);
            })
            ->whereNull('group_user.user_id') // Not already in VIP group
            ->where('users.money', '>=', $this->promotionAmount)
            ->get()
            ->toArray();
    }

    private function findDemotionCandidates()
    {
        return $this->db->table('users')
            ->select('users.id', 'users.username', 'users.money')
            ->join('group_user', function($join) {
                $join->on('users.id', '=', 'group_user.user_id')
                     ->where('group_user.group_id', '=', $this->promotionGroupId);
            })
            ->where('users.money', '<', $this->demotionAmount)
            ->get()
            ->toArray();
    }

    private function showChanges($promotions, $demotions, $verbose)
    {
        if (count($promotions) > 0) {
            $this->info('👑 PROMOTIONS (' . count($promotions) . ' users):');
            foreach ($promotions as $user) {
                if ($verbose) {
                    $this->line("   • User: {$user->username} (Balance: $" . number_format($user->money, 2) . ") → Adding to VIP Group");
                }
            }
            if (!$verbose && count($promotions) > 0) {
                $this->line("   • " . count($promotions) . " users will be promoted to VIP");
            }
        }

        if (count($demotions) > 0) {
            $this->info('👤 DEMOTIONS (' . count($demotions) . ' users):');
            foreach ($demotions as $user) {
                if ($verbose) {
                    $this->line("   • User: {$user->username} (Balance: $" . number_format($user->money, 2) . ") → Removing from VIP Group");
                }
            }
            if (!$verbose && count($demotions) > 0) {
                $this->line("   • " . count($demotions) . " users will be removed from VIP");
            }
        }
    }

    private function applyChanges($promotions, $demotions)
    {
        $this->db->transaction(function() use ($promotions, $demotions) {
            // Apply promotions
            foreach ($promotions as $user) {
                $this->db->table('group_user')->insert([
                    'user_id' => $user->id,
                    'group_id' => $this->promotionGroupId
                ]);
                
                $this->info("✅ User: {$user->username} (ID: {$user->id}) promoted to VIP Group");
            }

            // Apply demotions  
            foreach ($demotions as $user) {
                $this->db->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $this->promotionGroupId)
                    ->delete();
                    
                $this->info("✅ User: {$user->username} (ID: {$user->id}) removed from VIP Group");
            }
        });
    }
}
