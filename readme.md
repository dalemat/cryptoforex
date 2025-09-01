# Flarum Group Manager Extension

[![Latest Stable Version](https://img.shields.io/packagist/v/cryptoforex/group-manager.svg)](https://packagist.org/packages/cryptoforex/group-manager) [![Total Downloads](https://img.shields.io/packagist/dt/cryptoforex/group-manager.svg)](https://packagist.org/packages/cryptoforex/group-manager) [![License](https://img.shields.io/packagist/l/cryptoforex/group-manager.svg)](https://packagist.org/packages/cryptoforex/group-manager)

A powerful Flarum extension that automatically manages user groups based on their balance/money. Promote users when they reach certain amounts and demote them when they fall below thresholds.

## 🎯 Features
- ✅ **Automatic group promotion** based on user balance
- ✅ **Automatic group demotion** when balance drops
- ✅ **Configurable thresholds** for promotion/demotion
- ✅ **Comprehensive logging** with detailed statistics
- ✅ **Dry-run mode** for testing configuration
- ✅ **Full automation** with cron job support
- ✅ **Multiple output formats** (detailed, stats, silent)

## 📋 Requirements
- Flarum ^1.0
- PHP ^7.4 | ^8.0
- A money/balance system (compatible with antoinefr/flarum-ext-money)
- MySQL/MariaDB database
- Cron job access for automation (optional but recommended)

## 🚀 Installation

### Composer (Recommended)
```bash
composer require cryptoforex/group-manager
php flarum migrate
php flarum cache:clear



# Installation & Setup
composer require cryptoforex/group-manager && php flarum migrate && php flarum cache:clear

# Testing
php flarum group:manage --dry-run --detailed --stats

# Automation Setup (one command)
(crontab -l; echo "0 * * * * cd $(pwd) && php flarum group:manage --silent >> /var/log/group-manager.log 2>&1") | crontab -

# Monitoring
tail -f /var/log/group-manager.log

# Maintenance  
composer update cryptoforex/group-manager && php flarum cache:clear
