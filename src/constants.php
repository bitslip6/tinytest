<?php declare(strict_types=1);
namespace TinyTest;

const VER = '12';
const COVERAGE = 'c';
const TEST_FN = 't';
const SHOW_COVERAGE = 'r';
const ASSERT_CNT = 'assert_count';

function framework_root(): string
{
    return dirname(__DIR__);
}
