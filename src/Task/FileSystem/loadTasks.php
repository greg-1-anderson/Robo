<?php
namespace Robo\Task\FileSystem;

use Robo\TaskCollection\TransientManager;

trait loadTasks
{
    /**
     * @param $dirs
     * @return CleanDir
     */
    protected function taskCleanDir($dirs)
    {
        return new CleanDir($dirs);
    }

    /**
     * @param $dirs
     * @return DeleteDir
     */
    protected function taskDeleteDir($dirs)
    {
        return new DeleteDir($dirs);
    }

    /**
     * @param $dirs
     * @return TmpDir
     */
    protected function taskTmpDir($base = '', $prefix = 'tmp', $includeRandomPart = true)
    {
        return TransientManager::transientTask(new TmpDir($base, $prefix, $includeRandomPart));
    }

    /**
     * @param $dirs
     * @return CopyDir
     */
    protected function taskCopyDir($dirs)
    {
        return new CopyDir($dirs);
    }

    /**
     * @param $dirs
     * @return MirrorDir
     */
    protected function taskMirrorDir($dirs)
    {
        return new MirrorDir($dirs);
    }

    /**
     * @param $dirs
     * @return FlattenDir
     */
    protected function taskFlattenDir($dirs)
    {
        return new FlattenDir($dirs);
    }

    /**
     * @return FilesystemStack
     */
    protected function taskFilesystemStack()
    {
        return new FilesystemStack();
    }
}
