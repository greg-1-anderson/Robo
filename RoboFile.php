<?php
use Symfony\Component\Finder\Finder;
use Robo\Result;
use Robo\ResultData;
use Robo\Collection\CollectionBuilder;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;

class RoboFile extends \Robo\Tasks
{
    /**
     * Run the Robo unit tests.
     */
    public function test($args = "", $options =
        [
            'coverage-html' => false,
            'coverage' => false
        ])
    {
        $taskCodecept = $this->taskCodecept()
            ->args($args);

        if ($options['coverage']) {
            $taskCodecept->coverageXml('../../build/logs/clover.xml');
        }
        if ($options['coverage-html']) {
            $taskCodecept->coverageHtml('../../build/logs/coverage');
        }

        return $taskCodecept->run();
     }

    /**
     * Code sniffer.
     *
     * Run the PHP Codesniffer on a file or directory.
     *
     * @param string $file
     *    A file or directory to analyze.
     * @option $autofix Whether to run the automatic fixer or not.
     * @option $strict Show warnings as well as errors.
     *    Default is to show only errors.
     */
    public function sniff(
        $file = 'src/',
        $options = [
            'autofix' => false,
            'strict' => false,
        ]
    ) {
        $strict = $options['strict'] ? '' : '-n';
        $result = $this->taskExec("./vendor/bin/phpcs --standard=PSR2 {$strict} {$file}")->run();
        if (!$result->wasSuccessful()) {
            if (!$options['autofix']) {
                $options['autofix'] = $this->confirm('Would you like to run phpcbf to fix the reported errors?');
            }
            if ($options['autofix']) {
                $result = $this->taskExec("./vendor/bin/phpcbf --standard=PSR2 {$file}")->run();
            }
        }
        return $result;
    }

    /**
     * Generate a new Robo task that wraps an existing utility class.
     *
     * @param $className The name of the existing utility class to wrap.
     * @param $wrapperClassName The name of the wrapper class to create. Optional.
     * @usage generate:task 'Symfony\Component\Filesystem\Filesystem' FilesystemStack
     */
    public function generateTask($className, $wrapperClassName = "")
    {
        return $this->taskGenTask($className, $wrapperClassName)->run();
    }

    /**
     * Release Robo.
     */
    public function release($opts = ['beta' => false])
    {
        $this->yell("Releasing Robo");
        $stable = true;
        if ($opts['beta']) {
            $stable = false;
            $this->say('non-stable release');
        }

        $releaseDescription = $this->ask("Description of Release\n");

        $this->docs();
        $this->taskGitStack()
            ->add('-A')
            ->commit("auto-update")
            ->pull()
            ->push()
            ->run();

        if ($stable) $this->pharPublish();
        $this->publish();

        $this->taskGitHubRelease(\Robo\Runner::VERSION)
            ->user($this->ask('User releasing'))
            ->password($this->askHidden('Password'))
            ->uri('greg-1-anderson/Robo')
            ->description($releaseDescription)
            ->run();

        if ($stable) $this->versionBump();
    }

    /**
     * Update changelog.
     *
     * Add an entry to the Robo CHANGELOG.md file.
     *
     * @param string $addition The text to add to the change log.
     */
    public function changed($addition)
    {
        return $this->taskChangelog()
            ->version(\Robo\Runner::VERSION)
            ->change($addition)
            ->run();
    }

    /**
     * Update the version of Robo.
     *
     * @param string $version The new verison for Robo.
     *   Defaults to the next minor (bugfix) version after the current relelase.
     */
    public function versionBump($version = '')
    {
        if (empty($version)) {
            $versionParts = explode('.', \Robo\Runner::VERSION);
            $versionParts[count($versionParts)-1]++;
            $version = implode('.', $versionParts);
        }
        return $this->taskReplaceInFile(__DIR__.'/src/Runner.php')
            ->from("VERSION = '".\Robo\Runner::VERSION."'")
            ->to("VERSION = '".$version."'")
            ->run();
    }

    /**
     * Generate the Robo documentation files.
     */
    public function docs()
    {
        $collection = $this->collectionBuilder();
        $collection->progressMessage('Generate documentation from source code.');
        $files = Finder::create()->files()->name('*.php')->in('src/Task');
        $docs = [];
        foreach ($files as $file) {
            if ($file->getFileName() == 'loadTasks.php') {
                continue;
            }
            if ($file->getFileName() == 'loadShortcuts.php') {
                continue;
            }
            $ns = $file->getRelativePath();
            if (!$ns) {
                continue;
            }
            $class = basename(substr($file, 0, -4));
            class_exists($class = "Robo\\Task\\$ns\\$class");
            $docs[$ns][] = $class;
        }
        ksort($docs);

        foreach ($docs as $ns => $tasks) {
            $taskGenerator = $collection->taskGenDoc("docs/tasks/$ns.md");
            $taskGenerator->filterClasses(function (\ReflectionClass $r) {
                return !($r->isAbstract() || $r->isTrait()) && $r->implementsInterface('Robo\Contract\TaskInterface');
            })->prepend("# $ns Tasks");
            sort($tasks);
            foreach ($tasks as $class) {
                $taskGenerator->docClass($class);
            }

            $taskGenerator->filterMethods(
                function (\ReflectionMethod $m) {
                    if ($m->isConstructor() || $m->isDestructor() || $m->isStatic()) {
                        return false;
                    }
                    $undocumentedMethods =
                    [
                        '',
                        'run',
                        '__call',
                        'inflect',
                        'injectDependencies',
                        'getCommand',
                        'getPrinted',
                        'getConfig',
                        'setConfig',
                        'logger',
                        'setLogger',
                        'setProgressIndicator',
                        'progressIndicatorSteps',
                    ];
                    return !in_array($m->name, $undocumentedMethods) && $m->isPublic(); // methods are not documented
                }
            )->processClassSignature(
                function ($c) {
                    return "## " . preg_replace('~Task$~', '', $c->getShortName()) . "\n";
                }
            )->processClassDocBlock(
                function (\ReflectionClass $c, $doc) {
                    $doc = preg_replace('~@method .*?(.*?)\)~', '* `$1)` ', $doc);
                    $doc = str_replace('\\'.$c->name, '', $doc);
                    return $doc;
                }
            )->processMethodSignature(
                function (\ReflectionMethod $m, $text) {
                    return str_replace('#### *public* ', '* `', $text) . '`';
                }
            )->processMethodDocBlock(
                function (\ReflectionMethod $m, $text) {

                    return $text ? ' ' . trim(strtok($text, "\n"), "\n") : '';
                }
            );
        }
        $collection->progressMessage('Documentation generation complete.');
        return $collection->run();
    }

    /**
     * Publish Robo.
     *
     * Builds a site in gh-pages branch. Uses mkdocs
     */
    public function publish()
    {
        $current_branch = exec('git rev-parse --abbrev-ref HEAD');

        return $this->collectionBuilder()
            ->taskGitStack()
                ->checkout('site')
                ->merge('master')
            ->completion($this->taskGitStack()->checkout($current_branch))
            ->taskFilesystemStack()
                ->copy('CHANGELOG.md', 'docs/changelog.md')
            ->completion($this->taskFilesystemStack()->remove('docs/changelog.md'))
            ->taskExec('mkdocs gh-deploy')
            ->run();
    }

    /**
     * Build the Robo phar executable.
     */
    public function pharBuild()
    {
        // Make sure to remove dev files before finding the files to pack into
        // the phar.  This must therefore be done outside the collection,
        // as the files to pack are found when the collection is built.
        $this->taskComposerInstall()
            ->noDev()
            ->printed(false)
            ->run();

        $collection = $this->collectionBuilder();

        // revert back phar dependencies on completion
        $collection->completion($this
            ->taskComposerInstall()
            ->printed(false)
        );

        $packer = $collection->taskPackPhar('robo.phar');
        $files = Finder::create()->ignoreVCS(true)
            ->files()
            ->name('*.php')
            ->name('*.exe') // for 1symfony/console/Resources/bin/hiddeninput.exe
            ->path('src')
            ->path('vendor')
            ->exclude('symfony/config/Tests')
            ->exclude('symfony/console/Tests')
            ->exclude('symfony/event-dispatcher/Tests')
            ->exclude('symfony/filesystem/Tests')
            ->exclude('symfony/finder/Tests')
            ->exclude('symfony/process/Tests')
            ->exclude('henrikbjorn/lurker/tests')
            ->in(__DIR__);
        foreach ($files as $file) {
            $packer->addFile($file->getRelativePathname(), $file->getRealPath());
        }
        $packer->addFile('robo', 'robo')
            ->executable('robo');

        return $collection->run();
    }

    /**
     * Install Robo phar.
     *
     * Installs the Robo phar executable in /usr/bin. Uses 'sudo'.
     */
    public function pharInstall()
    {
        return $this->taskExec('sudo cp')
            ->arg('robo.phar')
            ->arg('/usr/bin/robo')
            ->run();
    }

    /**
     * Publish Robo phar.
     *
     * Commits the phar executable to Robo's GitHub pages site.
     */
    public function pharPublish()
    {
        $this->pharBuild();

        $this->_rename('robo.phar', 'robo-release.phar');
        return $this->collectionBuilder()
            ->taskGitStack()
                ->checkout('gh-pages')
            ->taskFilesystemStack()
                ->remove('robo.phar')
                ->rename('robo-release.phar', 'robo.phar')
            ->taskGitStack()
                ->add('robo.phar')
                ->commit('robo.phar published')
                ->push('origin', 'gh-pages')
                ->checkout('master')
                ->run();
    }

    /**
     * Watch a file.
     *
     * Demonstrates the 'watch' command. Runs 'composer update' any time
     * composer.json changes.
     */
    public function tryWatch()
    {
        $this->taskWatch()->monitor(['composer.json', 'composer.lock'], function () {
            $this->taskComposerUpdate()->run();
        })->run();
    }

    /**
     * Demonstrates Robo input APIs.
     */
    public function tryInput()
    {
        $answer = $this->ask('how are you?');
        $this->say('You are '.$answer);
        $yes = $this->confirm('Do you want one more question?');
        if (!$yes) {
            return Result::cancelled();
        }
        $lang = $this->askDefault('what is your favorite scripting language?', 'PHP');
        $this->say($lang);
        $pin = $this->askHidden('Ok, now tell your PIN code (it is hidden)');
        $this->yell('Ha-ha, your pin code is: '.$pin);
        $this->say('Bye!');
    }

    /**
     * Demonstrates parallel execution.
     *
     * @option $printed Print the output of each process.
     * @option $error Include an extra process that fails.
     */
    public function tryPara($options = ['printed' => false, 'error' => false])
    {
        $dir = __DIR__;
        $para = $this->taskParallelExec()
            ->printed($options['printed'])
            ->process("php $dir/tests/_data/parascript.php hey 4")
            ->process("php $dir/tests/_data/parascript.php hoy 3")
            ->process("php $dir/tests/_data/parascript.php gou 2")
            ->process("php $dir/tests/_data/parascript.php die 1");
        if ($options['error']) {
            $para->process("ls $dir/tests/_data/filenotfound");
        }
        return $para->run();
    }

    /**
     * Demonstrates Robo argument passing.
     *
     * @param string $a The first parameter. Required.
     * @param string $b The second parameter. Optional.
     */
    public function tryArgs($a, $b = 'default')
    {
        $this->say("The parameter a is $a and b is $b");
    }

    /**
     * Demonstrate Robo variable argument passing.
     *
     * @param $a A list of commandline parameters.
     */
    public function tryArrayArgs(array $a)
    {
        $this->say("The parameters passed are:\n" . var_export($a, true));
    }

    /**
     * Demonstrate Robo boolean options.
     *
     * @param $opts The options.
     * @option boolean $silent Supress output.
     */
    public function tryOptbool($opts = ['silent|s' => false])
    {
        if (!$opts['silent']) {
            $this->say("Hello, world");
        }
    }

    /**
     * Demonstrate the use of the PHP built-in webserver.
     */
    public function tryServer()
    {
        return $this->taskServer(8000)
            ->dir('site')
            ->arg('site/index.php')
            ->run();
    }

    /**
     * Demonstrate the use of the Robo open-browser task.
     */
    public function tryOpenBrowser()
    {
        return $this->taskOpenBrowser([
            'http://robo.li',
            'https://github.com/consolidation-org/Robo'
            ])->run();
    }

    /**
     * Demonstrate Robo error output and command failure.
     */
    public function tryError()
    {
        return $this->taskExec('ls xyzzy' . date('U'))->dir('/tmp')->run();
    }

    /**
     * Demonstrate Robo standard output and command success.
     */
    public function trySuccess()
    {
        return $this->taskExec('pwd')->run();
    }

    /**
     * Demonstrate Robo formatters.  Default format is 'table'.
     *
     * @field-labels
     *   first: I
     *   second: II
     *   third: III
     * @usage try:formatters --format=yaml
     * @usage try:formatters --format=csv
     * @usage try:formatters --fields=first,third
     * @usage try:formatters --fields=III,II
     */
    public function tryFormatters($options = ['format' => 'table', 'fields' => ''])
    {
        $outputData = [
            [ 'first' => 'One',  'second' => 'Two',  'third' => 'Three' ],
            [ 'first' => 'Eins', 'second' => 'Zwei', 'third' => 'Drei'  ],
            [ 'first' => 'Ichi', 'second' => 'Ni',   'third' => 'San'   ],
            [ 'first' => 'Uno',  'second' => 'Dos',  'third' => 'Tres'  ],
        ];
        // Note that we can also simply return the output data array here.
        return ResultData::message(new RowsOfFields($outputData));
    }

    /**
     * Demonstrate what happens when a command or a task
     * throws an exception.  Note that typically, Robo commands
     * should return Result objects rather than throw exceptions.
     */
    public function tryException($options = ['task' => false])
    {
        if (!$options['task']) {
            throw new RuntimeException('Command failed with an exception.');
        }
        return new ExceptionTask('Task failed with an exception.');
    }

    /**
     * Demonstrate deprecated task behavior.
     *
     * Demonstrate what happens when using a task that is created via
     * direct instantiation, which omits initialization done by the
     * container.  Emits a warning message.
     */
    public function tryDeprecated()
    {
        // Calling 'new' directly without manually setting
        // up dependencies will result in a deprecation warning.
        // @see RoboFile::trySuccess()
        return (new \Robo\Task\Base\Exec('pwd'))->run();
    }

    /**
     * Demonstrate the use of a collection builder to chain multiple tasks
     * together into a collection, which is executed once constructed.
     *
     * For demonstration purposes only; this could, of course, be done
     * with a single FilesystemStack.
     */
    public function tryBuilder()
    {
        return $this->collectionBuilder()
            ->taskFilesystemStack()
                ->mkdir('a')
                ->touch('a/a.txt')
            ->taskFilesystemStack()
                ->mkdir('a/b')
                ->touch('a/b/b.txt')
            ->taskFilesystemStack()
                ->mkdir('a/b/c')
                ->touch('a/b/c/c.txt')
            ->run();
    }

    public function tryBuilderRollback()
    {
        // This example will create two builders, and add
        // the first one as a child of the second in order
        // to demonstrate nested rollbacks.
        $collection = $this->collectionBuilder()
            ->taskFilesystemStack()
                ->mkdir('g')
                ->touch('g/g.txt')
            ->rollback(
                $this->taskDeleteDir('g')
            )
            ->taskFilesystemStack()
                ->mkdir('g/h')
                ->touch('g/h/h.txt')
            ->taskFilesystemStack()
                ->mkdir('g/h/i/c')
                ->touch('g/h/i/i.txt');

        return $this->collectionBuilder()
            ->progressMessage('Start recursive collection')
            ->addTask($collection)
            ->progressMessage('Done with recursive collection')
            ->taskExec('ls xyzzy' . date('U'))
                ->dir('/tmp')
            ->run();
    }

    public function tryWorkdir()
    {
        // This example works like tryBuilderRollback,
        // but does equivalent operations using a working
        // directory. The working directory is deleted on rollback
        $collection = $this->collectionBuilder();

        $workdir = $collection->workDir('w');

        $collection
            ->taskFilesystemStack()
                ->touch("$workdir/g.txt")
            ->taskFilesystemStack()
                ->mkdir("$workdir/h")
                ->touch("$workdir/h/h.txt")
            ->taskFilesystemStack()
                ->mkdir("$workdir/h/i/c")
                ->touch("$workdir/h/i/i.txt");

        return $this->collectionBuilder()
            ->progressMessage('Start recursive collection')
            ->addTask($collection)
            ->progressMessage('Done with recursive collection')
            ->taskExec('ls xyzzy' . date('U'))
                ->dir('/tmp')
            ->run();
    }

    /**
     * Demonstrates Robo temporary directory usage.
     */
    public function tryTmpDir()
    {
        // Set up a collection to add tasks to
        $collection = $this->collectionBuilder();

        // Get a temporary directory to work in. Note that we get a path
        // back, but the directory is not created until the task runs.
        $tmpPath = $collection->tmpDir();

        $result = $collection
            ->taskWriteToFile("$tmpPath/file.txt")
                ->line('Example file')

            // Print a progress message. The callback function is called immediately
            // before the message is printed.
            ->progressMessage(
                'The directory at {path} {verb}.',
                ['path' => $tmpPath, 'verb' => 'might have been created'],
                function ($context) {
                    // It would also be legitimate to `use ($tmpDir)` and reference that.
                    if (is_dir($context['path'])) {
                        $context['verb'] = 'was created';
                    } else {
                        // Should never get here: will roll back if creation fails.
                        $context['verb'] = 'was NOT created';
                    }
                    return $context;
                }
            )

            // Run the tasks that we build
            ->run();

        if (is_dir($tmpPath)) {
            $this->say("The temporary directory at $tmpPath was not cleaned up after the collection completed.");
        } else {
            $this->say("The temporary directory at $tmpPath was automatically deleted.");
        }

        return $result;
    }

    public function tryProgress()
    {
        $delayUntilProgressStart = \Robo\Robo::config()->get(\Robo\Config::PROGRESS_BAR_AUTO_DISPLAY_INTERVAL);
        $this->say("Progress bar will display after $delayUntilProgressStart seconds of activity.");

        $processList = range(1, 10);
        return $this->collectionBuilder()
            ->taskForEach($processList)
                ->iterationMessage('Processing {value}')
                ->call(
                    function ($value) {
                        // TaskForEach::call should only be used to do
                        // non-Robo operations. To use Robo tasks in an
                        // iterator, @see TaskForEach::withBuilder.
                        usleep(500000); // sleep for half a second
                    }
                )
            ->run();
    }

    public function tryIter()
    {
        $workdir = 'build/iter-example';
        $this->say("Creating sample direcories in $workdir.");

        $processList = ['cats', 'dogs', 'sheep', 'fish', 'horses', 'cows'];
        return $this->collectionBuilder()
            ->taskFilesystemStack()
                ->mkdir($workdir)
            ->taskCleanDir($workdir)
            ->taskForEach($processList)
                ->withBuilder(
                    function ($builder, $key, $value) use ($workdir) {
                        return $builder
                            ->taskFilesystemStack()
                                ->mkdir("$workdir/$value");
                    }
                )
            ->run();
    }
}

class ExceptionTask extends \Robo\Task\BaseTask
{
    protected $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function run()
    {
        throw new RuntimeException($this->message);
    }
}
