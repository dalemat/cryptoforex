<?php

namespace CryptoForex\GroupManager\Console;

use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

class GroupManagerCommand extends AbstractCommand
{
    // ✅ Command name and description
    protected $name = 'group:manage';
    protected $description = 'Manage user groups based on balance';

    // ✅ Private properties with proper types
    private $db;
    private $promotionGroupId = 5;    // VIP Group ID
    private $demotionGroupId = 3;     // Basic Group ID  
    private $promotionAmount = 500;   // $500 minimum for VIP
    private $demotionAmount = 100;    // Below $100 = lose VIP

    // ✅ Constructor with proper dependency injection
    public function __construct(ConnectionInterface $db)
    {
        parent::__construct();
        $this->db = $db;
    }

    // ✅ Configure method - defines command options
    protected function configure()
    {
        $this->addOption('stats', null, InputOption::VALUE_NONE, 'Show statistics only')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be changed without making changes')
             ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Show detailed output');
    }

    // ✅ Main execution method - NO PARAMETERS for Flarum
    protected function fire()
    {
        // ✅ Get options safely
        $isStatsOnly = $this->option('stats') ?? false;
        $isDryRun = $this->option('dry-run') ?? false;
        $isVerbose = $this->option('verbose') ?? false;

        // ✅ Stats only mode
        if ($isStatsOnly) {
            $this->showStatistics();
            return 0;
        }

        // ✅ Header output
        $this->info('🚀 GROUP MANAGER - ' . ($isDryRun ? 'DRY RUN MODE' : 'Processing Changes'));
        
        if ($isDryRun) {
            $this->warn('🧪 DRY RUN MODE - No changes will be made');
        }

        try {
            // ✅ Find candidates with proper error handling
            $promotionCandidates = $this->findPromotionCandidates();
            $demotionCandidates = $this->findDemotionCandidates();

            $totalChanges = count($promotionCandidates) + count($demotionCandidates);

            // ✅ Handle no changes scenario
            if ($totalChanges === 0) {
                $this->info('✅ No changes needed - all users are in correct groups');
                return 0;
            }

            // ✅ Show proposed changes
            $this->showChanges($promotionCandidates, $demotionCandidates, $isVerbose);

            // ✅ Apply changes if not dry run
            if (!$isDryRun) {
                $this->applyChanges($promotionCandidates, $demotionCandidates);
                $this->info('✅ All changes applied successfully');
            }

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    // ✅ Statistics display method
    private function showStatistics()
    {
        $this->info('📊 GROUP MANAGER STATISTICS');
        
        try {
            // ✅ Safe database queries with error handling
            $totalUsers = User::count();
            $vipUsers = User::whereHas('groups', function($query) {
                $query->where('id', $this->promotionGroupId);
            })->count();

            $highBalanceUsers = User::where('money', '>=', $this->promotionAmount)->count();
            $lowBalanceUsers = User::where('money', '<', $this->demotionAmount)->count();

            // ✅ Table display with proper data
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Users', $totalUsers],
                    ['Current VIP Users', $vipUsers],
                    ['Users with $' . number_format($this->promotionAmount) . '+', $highBalanceUsers],
                    ['Users with <$' . number_format($this->demotionAmount), $lowBalanceUsers],
                ]
            );

            // ✅ Show pending changes
            $promotionCandidates = $this->findPromotionCandidates();
            $demotionCandidates = $this->findDemotionCandidates();

            if (count($promotionCandidates) > 0 || count($demotionCandidates) > 0) {
                $this->warn('⚠️  Pending Changes:');
                $this->line('   • Users to promote: ' . count($promotionCandidates));
                $this->line('   • Users to demote: ' . count($demotionCandidates));
                $this->line('');
                $this->line('💡 Run without --stats to apply changes');
            } else {
                $this->info('✅ All users are in correct groups');
            }

        } catch (\Exception $e) {
            $this->error('❌ Error getting statistics: ' . $e->getMessage());
        }
    }

    // ✅ Find users to promote - with error handling
    private function findPromotionCandidates()
    {
        try {
            return User::where('money', '>=', $this->promotionAmount)
                ->whereDoesntHave('groups', function($query) {
                    $query->where('id', $this->promotionGroupId);
                })
                ->get();
        } catch (\Exception $e) {
            $this->error('Error finding promotion candidates: ' . $e->getMessage());
            return collect(); // Return empty collection
        }
    }

    // ✅ Find users to demote - with error handling
    private function findDemotionCandidates()
    {
        try {
            return User::where('money', '<', $this->demotionAmount)
                ->whereHas('groups', function($query) {
                    $query->where('id', $this->promotionGroupId);
                })
                ->get();
        } catch (\Exception $e) {
            $this->error('Error finding demotion candidates: ' . $e->getMessage());
            return collect(); // Return empty collection
        }
    }

    // ✅ Display changes to be made
    private function showChanges($promotions, $demotions, $verbose = false)
    {
        // ✅ Show promotions
        if (count($promotions) > 0) {
            $this->info('🚀 PROMOTIONS (' . count($promotions) . ' users):');
            
            if ($verbose) {
                foreach ($promotions as $user) {
                    $balance = number_format($user->money ?? 0, 2);
                    $this->line("   • {$user->username} (Balance: \${$balance}) → Adding to VIP Group");
                }
            } else {
                $this->line("   • " . count($promotions) . " users will be promoted to VIP");
            }
        }

        // ✅ Show demotions
        if (count($demotions) > 0) {
            $this->info('👤 DEMOTIONS (' . count($demotions) . ' users):');
            
            if ($verbose) {
                foreach ($demotions as $user) {
                    $balance = number_format($user->money ?? 0, 2);
                    $this->line("   • {$user->username} (Balance: \${$balance}) → Removing from VIP Group");
                }
            } else {
                $this->line("   • " . count($demotions) . " users will be removed from VIP");
            }
        }
    }

    // ✅ Apply database changes with transaction safety
    private function applyChanges($promotions, $demotions)
    {
        try {
            $this->db->transaction(function() use ($promotions, $demotions) {
                // ✅ Apply promotions safely
                foreach ($promotions as $user) {
                    // Check if user-group relationship already exists
                    $exists = $this->db->table('group_user')
                        ->where('user_id', $user->id)
                        ->where('group_id', $this->promotionGroupId)
                        ->exists();
                    
                    if (!$exists) {
                        $this->db->table('group_user')->insert([
                            'user_id' => $user->id,
                            'group_id' => $this->promotionGroupId
                        ]);
                        
                        $this->info("✅ {$user->username} promoted to VIP Group");
                    }
                }

                // ✅ Apply demotions safely
                foreach ($demotions as $user) {
                    $deleted = $this->db->table('group_user')
                        ->where('user_id', $user->id)
                        ->where('group_id', $this->promotionGroupId)
                        ->delete();
                        
                    if ($deleted > 0) {
                        $this->info("✅ {$user->username} removed from VIP Group");
                    }
                }
            });
        } catch (\Exception $e) {
            $this->error('❌ Database error: ' . $e->getMessage());
            throw $e;
        }
    }
}
