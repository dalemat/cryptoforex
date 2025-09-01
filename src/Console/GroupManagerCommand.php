<?php

namespace CryptoForex\GroupManager\Console;

use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GroupManagerCommand extends AbstractCommand
{
    protected $signature = 'group:manage';
    protected $description = 'Manage user groups based on balance';

    private $db;
    private $container;

    public function __construct(ConnectionInterface $db, Container $container)
    {
        parent::__construct();
        $this->db = $db;
        $this->container = $container;
    }

    protected function configure()
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be changed without making changes')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show statistics only')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Verbose output');
    }

    protected function fire(InputInterface $input, OutputInterface $output)
    {
        $isDryRun = $input->getOption('dry-run');
        $showStats = $input->getOption('stats');
        $verbose = $input->getOption('verbose');

        // Configuration (you can modify these values)
        $promotionGroupId = 4;      // VIP group
        $demotionGroupId = 3;       // Regular group
        $promotionAmount = 10000.00; // $10,000
        $demotionAmount = 5000.00;   // $5,000

        $output->writeln("🚀 <info>Flarum Group Manager Started</info>");
        $output->writeln("💰 Promotion threshold: $" . number_format($promotionAmount));
        $output->writeln("⬇️  Demotion threshold: $" . number_format($demotionAmount));
        
        if ($isDryRun) {
            $output->writeln("🧪 <comment>DRY RUN MODE - No changes will be made</comment>");
        }

        try {
            if ($showStats) {
                $this->showStats($output, $promotionAmount, $demotionAmount, $promotionGroupId, $demotionGroupId);
                return 0;
            }

            $promotionCount = $this->processPromotions($promotionAmount, $promotionGroupId, $demotionGroupId, $isDryRun, $output, $verbose);
            $demotionCount = $this->processDemotions($demotionAmount, $promotionGroupId, $demotionGroupId, $isDryRun, $output, $verbose);

            $output->writeln("");
            $output->writeln("✅ <info>Process completed successfully!</info>");
            $output->writeln("📊 Promotions: {$promotionCount}");
            $output->writeln("📊 Demotions: {$demotionCount}");

        } catch (\Exception $e) {
            $output->writeln("<error>❌ Error: " . $e->getMessage() . "</error>");
            return 1;
        }

        return 0;
    }

    private function processPromotions(float $promotionAmount, int $promotionGroupId, int $demotionGroupId, bool $isDryRun, OutputInterface $output, bool $verbose): int
    {
        // Find users eligible for promotion
        $users = $this->db->table('users')
            ->leftJoin('group_user', function($join) use ($promotionGroupId) {
                $join->on('users.id', '=', 'group_user.user_id')
                     ->where('group_user.group_id', '=', $promotionGroupId);
            })
            ->where('users.money', '>=', $promotionAmount)
            ->whereNull('group_user.user_id') // Not already in promotion group
            ->select('users.id', 'users.username', 'users.email', 'users.money')
            ->get();

        $count = 0;
        foreach ($users as $user) {
            if ($verbose) {
                $output->writeln("⬆️  Promoting: {$user->username} (Balance: $" . number_format($user->money, 2) . ")");
            }

            if (!$isDryRun) {
                // Remove from demotion group if exists
                $this->db->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $demotionGroupId)
                    ->delete();

                // Add to promotion group
                $this->db->table('group_user')->insert([
                    'user_id' => $user->id,
                    'group_id' => $promotionGroupId
                ]);
            }
            $count++;
        }

        if ($count > 0) {
            $output->writeln("⬆️  <info>Processed {$count} promotions</info>");
        }

        return $count;
    }

    private function processDemotions(float $demotionAmount, int $promotionGroupId, int $demotionGroupId, bool $isDryRun, OutputInterface $output, bool $verbose): int
    {
        // Find users eligible for demotion
        $users = $this->db->table('users')
            ->join('group_user', 'users.id', '=', 'group_user.user_id')
            ->where('users.money', '<', $demotionAmount)
            ->where('group_user.group_id', $promotionGroupId)
            ->select('users.id', 'users.username', 'users.email', 'users.money')
            ->get();

        $count = 0;
        foreach ($users as $user) {
            if ($verbose) {
                $output->writeln("⬇️  Demoting: {$user->username} (Balance: $" . number_format($user->money, 2) . ")");
            }

            if (!$isDryRun) {
                // Remove from promotion group
                $this->db->table('group_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $promotionGroupId)
                    ->delete();

                // Add to demotion group
                $this->db->table('group_user')->insert([
                    'user_id' => $user->id,
                    'group_id' => $demotionGroupId
                ]);
            }
            $count++;
        }

        if ($count > 0) {
            $output->writeln("⬇️  <info>Processed {$count} demotions</info>");
        }

        return $count;
    }

    private function showStats(OutputInterface $output, float $promotionAmount, float $demotionAmount, int $promotionGroupId, int $demotionGroupId)
    {
        $output->writeln("📊 <info>Group Manager Statistics</info>");
        $output->writeln("=====================================");

        // Total users
        $totalUsers = $this->db->table('users')->count();
        $output->writeln("👥 Total Users: {$totalUsers}");

        // Users by balance ranges
        $highBalance = $this->db->table('users')->where('money', '>=', $promotionAmount)->count();
        $mediumBalance = $this->db->table('users')->where('money', '>=', $demotionAmount)->where('money', '<', $promotionAmount)->count();
        $lowBalance = $this->db->table('users')->where('money', '<', $demotionAmount)->count();

        $output->writeln("💰 High Balance (≥$" . number_format($promotionAmount) . "): {$highBalance}");
        $output->writeln("💰 Medium Balance ($" . number_format($demotionAmount) . "-$" . number_format($promotionAmount-0.01) . "): {$mediumBalance}");
        $output->writeln("💰 Low Balance (<$" . number_format($demotionAmount) . "): {$lowBalance}");

        // Current group memberships
        $promotionGroupMembers = $this->db->table('group_user')->where('group_id', $promotionGroupId)->count();
        $demotionGroupMembers = $this->db->table('group_user')->where('group_id', $demotionGroupId)->count();

        $output->writeln("👑 Promotion Group Members: {$promotionGroupMembers}");
        $output->writeln("👤 Demotion Group Members: {$demotionGroupMembers}");

        // Eligible for changes
        $eligiblePromotion = $this->db->table('users')
            ->leftJoin('group_user', function($join) use ($promotionGroupId) {
                $join->on('users.id', '=', 'group_user.user_id')
                     ->where('group_user.group_id', '=', $promotionGroupId);
            })
            ->where('users.money', '>=', $promotionAmount)
            ->whereNull('group_user.user_id')
            ->count();

        $eligibleDemotion = $this->db->table('users')
            ->join('group_user', 'users.id', '=', 'group_user.user_id')
            ->where('users.money', '<', $demotionAmount)
            ->where('group_user.group_id', $promotionGroupId)
            ->count();

        $output->writeln("⬆️  Eligible for Promotion: {$eligiblePromotion}");
        $output->writeln("⬇️  Eligible for Demotion: {$eligibleDemotion}");
    }
}
