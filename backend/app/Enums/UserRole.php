<?php

namespace App\Enums;

enum UserRole: string
{
    case Client = 'client';
    case Curator = 'curator';
    case Trainer = 'trainer';
    case Admin = 'admin';
}
