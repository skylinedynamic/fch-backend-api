<?php

namespace App\Support\Files;

/**
 * The disk names code asks Storage for (BUG-016). UPLOADS routes each folder
 * to its own disk (FolderRoutingAdapter): private folders to the private disk,
 * the rest to PUBLIC. Code reads and writes uploads through UPLOADS only;
 * PUBLIC is read directly only by the commands that move old public files.
 */
final class Disk
{
    public const UPLOADS = 'uploads';
    public const PUBLIC = 'public';
    public const LOCAL = 'local';

    /** The driver behind UPLOADS, registered in AppServiceProvider. */
    public const FOLDER_ROUTING_DRIVER = 'folder-routing';
}
