<?php declare(strict_types=1);

/**
 * @author tanmingliang
 */

namespace Midi\Command;

use Midi\Container;
use Midi\Koala\Koala;
use Midi\Util\OS;
use Midi\Util\Util;
use Midi\Util\FileUtil;
use Midi\Plugin\PluginEvents;
use Midi\Plugin\Event\PreKoalaStart;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;
use Exception;

class ReplayerCommand extends BaseCommand
{
    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var Process
     */
    private $process;

    /**
     * @var string
     */
    private $startCMD;

    /**
     * Timeout 24 hour, for xdebug mode
     */
    const TIMEOUT_24H = '86400s';

    /**
     * @var Koala
     */
    protected $koala;

    protected function configure()
    {
        $this
            ->setHidden(true)
            ->setName('replayer')
            ->setDescription('manage koala replayer.')
            ->addOption('--start', '-s', InputOption::VALUE_OPTIONAL)
            ->addOption('--fast-start', '-f', InputOption::VALUE_OPTIONAL)
            ->addOption('--stop', '-t', InputOption::VALUE_OPTIONAL)
            ->addOption('--match-strategy', '-M', InputOption::VALUE_OPTIONAL,
                'set replay match strategy for traffic, support: `chunk` or `sim`', 'sim')
            ->addOption('--trace', '-T', InputOption::VALUE_NONE, 'generate Xdebug function traces')
            ->addOption('--xdebug', '-x', InputOption::VALUE_NONE,
                'replay with xdebug, you could set breakpoint when replay');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->koala = $this->getMidi()->getKoala();

        $options = [
            'match'  => $input->getOption('match-strategy'),
            'trace'  => $input->getOption('trace'),
            'xdebug' => $input->getOption('xdebug'),
        ];

        $config = $this->getMidi()->getConfig();
        $timeout = $config->get('koala', 'inbound-read-timeout');
        if (!empty($timeout)) {
            $options['KOALA_INBOUND_READ_TIMEOUT'] = $timeout;
        }
        $timeout = $config->get('koala', 'gc-global-status-timeout');
        if (empty($timeout)) {
            $options['KOALA_GC_GLOBAL_STATUS_TIMEOUT'] = $timeout;
        }

        // Under xdebug mode, set read & gc timeout 24 hour
        if ($input->getOption('xdebug')) {
            $options['KOALA_INBOUND_READ_TIMEOUT'] = $config->get('koala', 'inbound-read-timeout') ?? self::TIMEOUT_24H;
            $options['KOALA_GC_GLOBAL_STATUS_TIMEOUT'] = $config->get('koala', 'gc-global-status-timeout') ?? self::TIMEOUT_24H;
        }

        switch ($this->output->getVerbosity()) {
            case OutputInterface::VERBOSITY_DEBUG:
                // -vvv == Koala TRACE, log is too big, so write to file
                $options['KOALA_LOG_LEVEL'] = 'TRACE';
                $options['KOALA_LOG_FILE'] = Container::make('koalaLog');
                break;
            case OutputInterface::VERBOSITY_VERY_VERBOSE:
                $options['KOALA_LOG_LEVEL'] = 'DEBUG';
                break;
            case OutputInterface::VERBOSITY_VERBOSE:
                $options['KOALA_LOG_LEVEL'] = 'WARN';
                break;
            case OutputInterface::VERBOSITY_NORMAL:
            default:
                $options['KOALA_LOG_LEVEL'] = 'ERROR';
        }

        if ($input->getOption('start')) {
            $this->start($options);
        } elseif ($input->getOption('stop')) {
            $this->stop();
        } elseif ($input->getOption('fast-start')) {
            $this->fastStart($options);
        }
    }

    /**
     * @param array $options
     * @throws Exception
     */
    public function start(array $options)
    {
        $this->preStartCheck();

        $event = new PreKoalaStart(
            PluginEvents::PRE_KOALA_START,
            $this->getMidi(),
            $this->getApplication(),
            $options,
            $this->output
        );
        $this->getEventDispatcher()->dispatch($event->getName(), $event);

        $this->fastStart($event->getOptions());
        register_shutdown_function([$this, 'stop']);
    }

    /**
     * Fast start koala without pre check, used when want to restart koala
     *
     * @param array $options
     * @throws \Midi\Exception\ContainerException
     * @throws \Midi\Exception\RuntimeException
     */
    public function fastStart(array $options)
    {
        if ($this->process && $this->process->isRunning()) {
            $this->stop();
        }
        if (empty($this->startCMD)) {
            $this->startCMD = $this->koala->getStartCMD($options);
        }

        // Notice for Linux, LD_PRELOAD in env pass to Process will not work, but macOS is ok.
        // So, add LD_PRELOAD or DYLD_INSERT_LIBRARIES in CMD
        $this->process = new Process($this->startCMD);
        $this->process->start();
        $pid = $this->process->getPid();
        $this->output->writeln(sprintf("<info>Finish start replayer, cmd=`%s`, pid=%d.</info>", $this->startCMD, $pid),
            OutputInterface::VERBOSITY_VERY_VERBOSE
        );
    }

    public function stop()
    {
        $this->output->writeln("<info>Koala replayer output:</info>", OutputInterface::VERBOSITY_VERY_VERBOSE);
        $this->output->writeln("<info>" . $this->process->getOutput() . "</info>",
            OutputInterface::VERBOSITY_VERY_VERBOSE);
        if ($this->output->getVerbosity() === OutputInterface::VERBOSITY_DEBUG) {
            $this->output->writeln("<info>More replayer output at: " . Container::make('koalaLog') . "</info>");
        }
        $this->process->stop();

        // But, LD_PRELOAD add in CMD will get wrong process id, wrong pid +1 = real pid
        // So, When at linux platform we need register_shutdown_function to kill php -S server.
        if (OS::isLinux()) {
            try {
                Util::checkPortsAvailable($this->koala->getPorts());
            } catch (\Exception $e) {
                static::stopReplayer($this->koala->getPorts());
            }
        }
    }

    /**
     * @throws Exception
     */
    private function preStartCheck()
    {
        static::prepareReplayerSo();
        static::prepareStaticFiles();

        Util::checkPortsAvailable($this->koala->getPorts());

        // need more check? subscribe preKoalaStart event
    }

    /**
     * If koala-replayer.so not exist, copy from phar
     *
     * @param bool $silent
     * @throws \Midi\Exception\ContainerException
     * @throws \Midi\Exception\ContainerValueNotFoundException
     */
    public static function prepareReplayerSo($silent = false)
    {
        $replayerDir = Container::make('replayerDir');
        $finder = new Finder();
        $count = $finder->in($replayerDir)->name(Koala::REPLAYER_NAME)->depth(0)->count();
        if ($count == 0) {
            $from = Container::make('pharReplayerDir') . DR . Koala::REPLAYER_NAME;
            $to = $replayerDir . DR . Koala::REPLAYER_NAME;
            copy($from, $to);
            if (!$silent) {
                Container::make('output')->writeln("<info>Cp replayer to $to.</info>",
                    OutputInterface::VERBOSITY_VERBOSE);
            }
        }
    }

    public static function prepareStaticFiles()
    {
        $staticTmpDir = Container::make('reportDir') . DR . 'static';
        if (!is_dir($staticTmpDir)) {
            $static = Container::make('templateDir') . DR . 'static';
            FileUtil::copyDir($static, $staticTmpDir);
        }
    }

    /**
     * Get replayer's pid by replayer's inbound & outbound ports.
     *
     * Inbound's pid === Outbound's pid
     *
     * @param array $ports
     * @return int -1 replayer not exist, 0 stop success, 1 stop fail
     */
    public static function stopReplayer($ports, $signal = 9)
    {
        $pids = [];
        foreach ($ports as $port) {
            $p = new Process("lsof -ti:$port");
            $p->run();
            $pid = trim($p->getOutput());
            if (!is_numeric($pid)) {
                return -1;
            }
            $pids[$pid] = $pid;
            if (count($pids) > 1) {
                return -1;
            }
        }

        if (empty($pids)) {
            return -1;
        }
        $pid = intval(current($pids));

        if (function_exists('posix_kill')) {
            $ok = @posix_kill($pid, $signal);
        } elseif ($ok = proc_open(sprintf('kill -%d %d', $signal, $pid), array(2 => array('pipe', 'w')), $pipes)) {
            $ok = false === fgets($pipes[2]);
        }
        if (!$ok) {
            return 1;
        }
        return 0;
    }
}
