<?php

namespace App\Deployments;

enum RunStartOutcome
{
    /** The run was created and its job queued. */
    case Started;

    /** Another run of the deployment is queued or running: nothing was created. */
    case Busy;

    /** The deploy would remove installations: confirmation required, nothing was created. */
    case RemovalsPending;

    /** The run was created but the job could not be queued: the run is closed as failed (Ruling F4). */
    case DispatchFailed;
}
