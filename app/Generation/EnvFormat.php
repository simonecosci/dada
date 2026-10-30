<?php

namespace App\Generation;

enum EnvFormat
{
    /** Valori tra apici, per il dotenv di docker compose. */
    case Quoted;

    /** Righe KEY=value letterali, per `docker run --env-file` e `docker stack`. */
    case Raw;
}
