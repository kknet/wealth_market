<?php

/**
 * cron调度入口程序
 */

use Illuminate\Database\Capsule\Manager as DB;

class DaemonController extends AbstractController
{

    // 当前服务器ip
    public static $server_ip = array();

    // 存储进程信息, 方便进程结束后清理进程信息
    public static $_process_info = array();

    // 存储当前cron的信息, 主要是重启次数等
    public static $_cron_info = array();

    // 当前服务器信息
    public static $_server_info = array();

    public static $_alert_users = array(
        '15010129801', //王洪军
    );

    public static $_timeout_info = array();

    public static $_master_pid = 0;

    // cron 表达式执行的最小时间单位, 每分钟
    const CRON_MIN_TIME = 60000;

    // 心跳更新时间间隔
    const HEARTBEAT_MIN_TIME = 10000;

    const ZERO = 0;

    // 服务器停止心跳时间, 2min
    const DIE_TIME = 120000;

    const KILL_PROCESS_TIME = 10000; // 每10s检查是否进程需要杀死

    const TIMEOUT_FORCE_KILL = 600000; // 强制杀死的时间

    // 执行cron exec的进程重启次数
    const EXEC_RETRY_COUNT = 3;

    const CHECK_HEARTBEAT_TYPE = 1; // 检查心跳的方式1-mysql, 2-tcp

    public static $crash_sever_cron_switch = 0; // server宕机之后是否自动切换cron
    public static $sms_alert_switch = 0; //短信发送开关

    public function init()
    {
        parent::init(); // TODO: Change the autogenerated stub
        self::$crash_sever_cron_switch = $this->_config->crash_sever_cron_switch;
        self::$sms_alert_switch = $this->_config->sms_alert_switch;
    }

    /**
     * 后台daemon入口程序
     */

    public function processAction()
    {
        Cli::msg('process入口', 'process入口程序正在执行', 'normal');

        self::set_master_pid();

        self::add_daemon_record();

        self::kill_process_timer();

        self::check_server_timer();

        self::process_exec_timer();

        // 定义完执行时间器之后应该手动执行一次

        self::update_and_check_server();

        self::_process_exec();

        // 注册信号事件
        self::_register_signal();

    }


    public static function set_master_pid()
    {
        self::$_master_pid = posix_getpid();
    }

    private static function get_master_pid()
    {
        return self::$_master_pid;
    }

    public function add_daemon_record()
    {
        $datetime = date('Y-m-d H:i:s');
        $ret = Models_Daemonrecord::create(array('create_time' => $datetime, 'ip' => Tool::get_server_ip()));
        if ($ret == false)
        {
            Models_Daemonrecord::create(array('create_time' => $datetime, 'ip' => Tool::get_server_ip()));
        }
    }


    /**
     * @param $cron_id
     * @param string $restart_type
     * return bool
     * @description 后台手动杀死进程
     */
    public function killprocessAction()
    {

        $cron_id = Util_Common::post('cron_id');
        $restart_type = Util_Common::post('restart_type');
        if (empty($cron_id))
        {
            _error_json_encoder('错误, cron_id为空');
        }
        if (empty($restart_type))
        {
            $restart_type == PROCESS_MANUAL_RESTART;
        }
        $cron = Models_Crontab::where('id', $cron_id)->first();
        $keywords = explode(',', $cron->args);
        $keywords = $keywords[0];
        $pid = Process::get_pid_by_keywords($keywords);
        $time = Tool::get_milli_second();
        if (empty($pid))
        {
            self::_finish(
                $cron->id,
                array(
                    'process_restart_type' => $restart_type,
                    'finish_time' => $time
                ),
                false
            );
            _success_json_encoder('该进程在上一个时间段已经执行完了, 后台在下一次运行的时候不会继续执行, 无须再次操作');
        }
        else
        {
            try
            {
                $unset_pid = $pid;
                $pid = implode(' ', $pid);
                $ret = \Swoole\Process::kill($pid, SIGKILL);
                self::_finish(
                    $cron->id,
                    array(
                        'process_restart_type' => $restart_type,
                        'finish_time' => $time
                    ),
                    false
                );
                _success_json_encoder('进程杀死成功，杀死的进程号为' . json_encode($pid) . json_encode($ret));
            }
            catch (\Exception $e)
            {
                _error_json_encoder("pid{$pid}" . $e->getMessage());
            }


        }
    }


    public static function kill_process_timer()
    {
        $kill_process_time = self::KILL_PROCESS_TIME;
        \Swoole\Timer::after($kill_process_time, function() {
            Cli::msg('10s定时检查超时进程', 'start........', 'normal');
            self::_kill_timeout_process();
            self::kill_process_timer();
        });
    }

    public static function check_server_timer()
    {
        $server_after_time = self::HEARTBEAT_MIN_TIME; // 10s更新一次
        \Swoole\Timer::after($server_after_time, function()
        {
            Cli::msg('10s定时检查更新服务器时间', 'start........', 'normal');
            self::update_and_check_server();
            self::check_server_timer();
        });
    }


    /**
     * 循环执行计划任务
     */
    public static function process_exec_timer()
    {
        $after_time = self::CRON_MIN_TIME;  // 1min钟
        \Swoole\Timer::after($after_time, function(){
            Cli::msg('60s定时循环次数', 'start........', 'normal');
            self::process_exec_timer();
            self::_process_exec();
        });

    }


    private static function _kill_timeout_process()
    {
        $time = Tool::get_milli_second();
        if (!empty(self::$_timeout_info))
        {
            foreach (self::$_timeout_info as $pid => $time_info)
            {
                if ($time >= $time_info['expire_time'])
                {
                    $signal = $time_info['timeout_kill_type'];
                    if ($signal == SIGTERM && $time - $time_info['expire_time'] > self::TIMEOUT_FORCE_KILL)
                    {
                        $signal = SIGKILL;
                    }

                    try {
                        $ret = \Swoole\Process::kill($pid, $signal);
                        var_dump("超时了杀死了吗， 老铁$ret");
                    }
                    catch (\Exception $e)
                    {
                        echo $e->getMessage();
                    }

                    self::_finish(
                        $time_info['id'],
                        array(
                            'exit_code' => EXIT_CODE_4001,
                            'finish_time' => $time
                        )
                    );
                    unset(self::$_timeout_info[$pid]);
                    unset(self::$_process_info[$pid]);
                    Cli::msg('kill-signal', "kill-{$signal}-pid-{$pid}");
                }
            }
        }
    }

    /*
     * 执行当前时间的所有crontab
     */
    protected static function _process_exec()
    {
        $server_ip = Tool::get_server_ip();
        $current_server = Models_Server::where('ip', $server_ip)->first();
        if (empty($current_server))
        {
            exit(0);
        }
        $current_server_cron = Models_Crontab::where('server_id', $current_server->id)->get();

        if (!empty($current_server_cron))
        {
            foreach ($current_server_cron as &$cron)
            {
                $cron->args = explode(',', $cron->args);
                if ($cron->server_id == $current_server->id)
                {
                    // 此条cron指定该台server执行
                    self::_run($cron);
                }
            }
        }

    }

    /**
     * 判断cron是否可以执行
     * @param $cron 对象格式
     */
    protected static function _run($cron, $time = null)
    {
        $exec = false;
        $time || $time = time();

        // 初始化某个cron的重启次数
        self::$_cron_info[$cron->id] = array(
            'repeat_num' => 0
        );

        $is_normal_status = true;
        if (Models_Crontab::checkTime($time, $cron->timer))
        {
            // EXIT_CODE_4003 表示后台人工杀死cron, 必须靠人工重启
            if ($cron->process_restart_type != PROCESS_MANUAL_RESTART)
            {
                $exec = true;
            }
        }
        else
        {
            if (!self::is_cron_normal_status($cron))
            {
                $is_normal_status = false;
            }
        }
        if (!$is_normal_status) {
            // 非正常退出状态判断是否允许重启
            if (($cron->repeat_num != 0) && ($cron->process_restart_type != PROCESS_MANUAL_RESTART))
            {
                $exec = true;
            }
        }

        $exec && self::_exec($cron);
    }

    /**
     * 执行某条cron
     * @param $cron
     */
    protected static function _exec($cron)
    {
        $proc = new Proc();
        $pids = $proc->showPids($cron->exec_file, $cron->args);

        if (self::get_master_pid() != $cron->master_pid)
        {
            //表示入口程序已经重启了
            if (count($pids) > 0)
            {
                // 上一次主进程退了, 但是process进程没有退出的情况
            }
        }


        if (is_file($cron->exec_file))
        {
            $process = new \Swoole\Process(function(\Swoole\Process $swoole_process) use ($cron) {
                $swoole_process->exec($cron->exec_file, $cron->args);
            }, true);

            $retry_count = 0;
            self::_finish($cron['id'], array('master_pid' => self::get_master_pid()));
            while (true) {
                $pid = $process->start();
                if ($pid == false) {
                    Cli::msg('cron启动失败', "{$cron->name}启动失败, 尝试重启,错误码为{$process->swoole_errno}, 错误信息为{$process->swoole_strerror}", 'error');
                    if ($retry_count <= self::EXEC_RETRY_COUNT)  {
                        $retry_count++;
                    } else {
                        break;
                    }
                } else {
                    break;
                }
            }
            if ($pid == false) {
                Cli::msg('cron最终启动失败', "{$cron->name}启动失败, 最终启动失败,错误码为{$process->swoole_errno}, 错误信息为{$process->swoole_strerror}", 'error');
                return ;
            }
            echo '====主进程' . self::get_master_pid();
            echo '=====' .$pid;
            // 考虑启动失败的情况, 重试,
            Cli::msg($cron->name, ' 开始执行, 父进程id为.' . posix_getpid() .'cron进程id为' . $pid);
            usleep(mt_rand(10000, 30000));
            $start_time = Tool::get_milli_second();
            // 不同机器的php脚本如果对同一个cron操作, 必须将该cron扩展整多个pd_crontab记录, 减少并发的危险操作
            self::_finish(
                $cron['id'],
                array(
                    'start_time' => $start_time,
                    'exit_code' => 0,
                    'finish_time' => self::ZERO
                )
            );

            self::$_process_info[$pid] = array(
                'id' => $cron->id,
                'name' => $cron->name,
                'server_id' => $cron->server_id,
                'start_time' => $start_time,
                'process' => $process
            );

            if (!empty($cron->timeout))
            {
                self::$_timeout_info[$pid] = array(
                    'id' => $cron->id,
                    'expire_time' => $cron->timeout * 1000 + $start_time,
                    'timeout_kill_type' => $cron->timeout_kill_type
                );
            }

            $redirect = $cron->redirect;
            // 读子进程(echo)输出内容，写入定向文件
            \Swoole\Event::add($process, function(\Swoole\Process $process) use ($redirect) {
                $content = $process->read(60000);
                if ($redirect) {
                    \Swoole\Async::writeFile($redirect, $content, null, FILE_APPEND);
                }
            });



        }
    }

    /**
     * 注册进程信号事件
     */
    protected static function _register_signal()
    {
        // 注册子进程停止信号

        // @todo, 当父进程意外退出之后咋办, 由supervisor重启吗

        \Swoole\Process::signal(SIGCHLD, function($sig) {
            //必须为false，非阻塞模式
            while(($ret = \Swoole\Process::wait(false)) == true) {
                // $ret格式说明, code-状态退出码(比如cron里的php发生fatal error的时候code值为非0), signal-信号量
                usleep(mt_rand(10000, 30000));
                $finish_time = Tool::get_milli_second();
                $cron_id = self::$_process_info[$ret['pid']]['id'];
                if ($ret['signal'] != 0 || $ret['code'] != 0) {
                    Cli::msg(self::$_process_info[$ret['pid']]['name'], ' 发生异常结束，进程id为' . $ret['pid'], 'error');
                } else {
                    Cli::msg(self::$_process_info[$ret['pid']]['name'], ' 正常结束，进程id为' . $ret['pid']);
                }

                // 数据表中不关心到底是因为php的fatal error退出的还是因为kill 进程导致进程退出的, 只关心进程是否已经退出了
                $exit_code = $ret['code'] != 0 ? $ret['code'] : ($ret['signal'] != 0 ? $ret['signal'] : 0);
                self::_finish(
                    $cron_id,
                    array(
                        'finish_time' => $finish_time,
                        'exit_code' => $exit_code
                    )
                );
                self::_clear_cron_info($cron_id);
                self::_clear_process($ret['pid']);
            }
        });

        // 这两个是检测主进程信号的, 不是子进程
        \Swoole\Process::signal(SIGINT, [__CLASS__, 'register_die']);
        \Swoole\Process::signal(SIGTERM, [__CLASS__, 'register_die']);
    }


    /**
     * 注册死亡信号
     * @param $signal
     */
    public static function register_die($signal)
    {
        echo '我手动点击了杀死信息' . $signal;
        Cli::msg('操作了死亡信号', '信号为' . $signal, 'error');

        // 父进程意外退出之后, 依赖软件supervisor进行重启, 可能在1分钟之内会产生一些僵尸进程
        // supervisor restart
        if (self::$sms_alert_switch)
        {
            SMS_Send::send(15010129801, "主进程daemon.php挂了\n");
        }
        exit(0);
    }


    protected static function _finish($cron_id, $update_data, $msg = true)
    {
        $affected_rows = Models_Crontab::where('id', $cron_id)->update($update_data);
        if ($affected_rows == 1)
        {
            if ($msg)
            {
                Cli::msg('更新成功', "cron_id: {$cron_id}, data: " . json_encode($update_data));
            }
        }
        else
        {
            Models_Crontab::where('id', $cron_id)->update($update_data);
        }

    }


    /**
     * 子进程结束后, 清理进程数据
     * @param $pid
     */
    protected static function _clear_process($pid)
    {
        \Swoole\Event::del(self::$_process_info[$pid]['process']);
        self::$_process_info[$pid]['process']->close();
        unset(self::$_process_info[$pid]);
        unset(self::$_timeout_info[$pid]);
    }

    /**
     * 子进程结束后, 清理cron信息
     * @param $cron
     */
    protected static function _clear_cron_info($cron_id)
    {
        unset(self::$_cron_info[$cron_id]);
    }


    protected static function update_and_check_server($type = 1)
    {
        $server_ip = Tool::get_server_ip();
        $datetime = date('Y-m-d H:i:s');
        $current_server = Models_Server::where('ip', '=', $server_ip)->first();
        if (empty($current_server))
        {
            Cli::msg('server更新', "查询不到该server,ip:{$server_ip}", 'error');
            exit();
        }

        $current_server->update_time = $datetime;
        $res = $current_server->save();
        Cli::msg('sever_update_ok', "更新server update_time 成功 {$datetime}");
        if ($res == false)
        {
            $current_server->save();
            Cli::msg('sql错误', '定时更新服务器状态错误', 'error');
            exit();
        }

        $all_servers = Models_Server::all();
        $time = time();
        $crash_server_ip = array();
        foreach ($all_servers as $server)
        {
            // 心跳时间设置为2min, 防止主从同步和daemon.php开始执行开始时间不一样导致的时间差
            $is_first_run = strtotime($server->create_time) == strtotime($server->update_time);
            if (!$is_first_run && ($time - strtotime($server->update_time) >= self::DIE_TIME / 1000))
            {
                $server->status = 1;
                $server->save();
                Cli::msg('server死亡', 'server_id: ' . $server->id. '已经死亡，切换开关为:' . self::$crash_sever_cron_switch . ' ,开关打开则切换该crash server下的所有cron', 'error');
                $crash_server_ip[] = $server->ip;
                if (self::$crash_sever_cron_switch != 1) {
                    continue;
                }
                $crash_server_cron = Models_Crontab::where('server_id', $server->id)->get();
                if (!empty($crash_server_cron))
                {
                    foreach ($crash_server_cron as $cron)
                    {
                        // 更改该cron的server_id
                        $replace_server_id = Models_Server::replace_to_another_server($cron->server_id, Tool::filter_by_field($all_servers->toArray(), 'id'));
                        self::_replace_cron_server($cron, $replace_server_id);
                    }
                }

            }
        }

        if (!empty($crash_server_ip))
        {
            $ip = implode(', ', $crash_server_ip);
            foreach (self::$_alert_users as $user)
            {
                if (self::$sms_alert_switch != 0)
                {
                    SMS_Send::send($user, "server挂掉了, ip: {$ip}");
                }
            }
        }

    }



    /**
     * 更换cron的运行server
     * @param $cron
     * @param $replace_server_id
     */
    protected static function _replace_cron_server($cron, $replace_server_id)
    {
        $another_server_cron = Models_Crontab::where('name', $cron->name)->where('server_id', $replace_server_id)->first();
        $datetime = Tool::get_milli_second();

        if (!empty($another_server_cron))
        {
            $another_server_cron->exit_code = EXIT_CODE_4000;
            $another_server_cron->start_time = $datetime;
            $another_server_cron->finish_time = $datetime;
            $res = $another_server_cron->save();
            if ($res === false)
            {
                $res = $another_server_cron->save();
            }
            Cli::msg('cron更换server', ' cron_id: ' . $cron->id. '在server_id: ' . $replace_server_id . '已经运行了, 标记该cron异常退出即可', 'warning');

        }
        else
        {
            $cron->server_id = $replace_server_id;
            $cron->exit_code = EXIT_CODE_4000;
            $cron->start_time = $datetime;
            $cron->finish_time = $datetime;
            $res = $cron->save();
            if ($res === false)
            {
                $res = $cron->save();
            }
            Cli::msg('cron更换server', ' cron_id: ' . $cron['id']. '由之前的server_id: ' . $cron->id . ' 变更为server_id: ' . $replace_server_id, 'warning');
        }

    }


    /**
     * 该cron是否处在正常状态
     * @param $cron
     */
    protected static function is_cron_normal_status($cron)
    {
        return $cron->exit_code == 0;
    }




    //====================start================== 下面都是基于页面的一些展示

    public function processinfoAction()
    {
        $cron_id = Util_Common::post('cron_id');
        $cron = Models_Crontab::where('id', $cron_id)->first();
        $keywords = explode(',', $cron->args);
        $keywords = $keywords[0];
        $pid = Process::get_pid_by_keywords($keywords);
        $process_data = array();
        if (!empty($pid))
        {
            foreach ($pid as $_pid)
            {
                $process_info = Process::get_info_by_pid($_pid);
                if (isset($process_info[1]) && !empty($process_info[1]))
                {
                    $process_info = array_values(array_filter(explode(' ', $process_info[1])));
                    // 0-进程号 1-可执行文件 2-开始执行时间 3-已经执行的时间 4-pid 5-gid
                    $process_data[] = $process_info;
                }
            }
        }

        _json_encoder(
            array(
                'db_data' => $cron->toArray(),
                'process_data' => $process_data,
                'status' => true
            )
        );
    }

}
