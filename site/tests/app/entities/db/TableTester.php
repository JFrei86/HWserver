<?php

declare(strict_types=1);

namespace tests\app\entities\db;

use app\entities\db\Table;
use PHPUnit\Framework\TestCase;

class TableTester extends TestCase {
    public function testConstructorThrowsException(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create new information_schema.table');
        new Table();
    }
}
