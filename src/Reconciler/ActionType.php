<?php

namespace AssetMapperUpdate\Reconciler;

enum ActionType: string
{
    case Create = 'create';
    case Update = 'update';
    case Keep = 'keep';
    case Close = 'close';
    /** The branch has commits the action did not make: it is never force-pushed or closed. */
    case Blocked = 'blocked';
    /** `open_pull_requests_limit` reached. */
    case Defer = 'defer';
    /** The same update was closed by a human without merging. */
    case SkipClosed = 'skip-closed';
}
