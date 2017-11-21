<?php

define("BASE_URL", 'http://cron_static.weibo.com');

define('EXIT_CODE_4000', 4000); // 由于某一台机器挂掉, 某个cron转换到另外一个server时候的退出码
define('EXIT_CODE_4001', 4001); // daemon程序判断超过了cron的超时时间杀死的exit_code
define('EXIT_CODE_4002', 4002); // 管理界面手动点击按钮杀死cron的exit_code(默认允许daemon脚本重启, 前提是该cron本身允许重启)
define('EXIT_CODE_4003', 4003); // 管理界面手动点击按钮杀死cron的exit_code(不允许daemon脚本重启, 这种情况只能靠手动重启)



define('PROCESS_AUTO_RESTART', 'auto_restart');
define('PROCESS_MANUAL_RESTART', 'manual_restart');

define('SEND_SMS_SWITCH', false);
define('DEBUG', true);
