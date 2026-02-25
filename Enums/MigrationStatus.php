<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Enums;

enum MigrationStatus: string
{
    case SCANNED = 'scanned';
    case QUEUED = 'queued';
    case DISCOVERING = 'discovering';
    case BACKING_UP = 'backing_up';
    case RESTORING = 'restoring';
    case VALIDATING = 'validating';
    case SUCCESS = 'success';
    case FAILED = 'failed';
}
