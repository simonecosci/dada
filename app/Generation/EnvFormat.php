<?php

namespace App\Generation;

enum EnvFormat
{
    /** Quoted values, for the docker compose dotenv. */
    case Quoted;

    /** Literal KEY=value lines, for `docker run --env-file` and `docker stack`. */
    case Raw;
}
