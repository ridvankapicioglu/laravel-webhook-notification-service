<?php

namespace App\Enums;

enum Priority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * Each priority has its own queue. Workers consume them in high -> normal -> low order.
     */
    public function queue(): string
    {
        return $this->value;
    }
}
