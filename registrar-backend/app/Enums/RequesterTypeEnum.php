<?php

namespace App\Enums;

/**
 * Who a resolved academic record belongs to. One case per resolution tier
 * in AcademicRecordResolver.
 */
enum RequesterTypeEnum: string
{
    case Student               = 'Student';
    case Alumni                = 'Alumni';
    case UndergraduateRequester = 'Undergraduate Requester';
}
