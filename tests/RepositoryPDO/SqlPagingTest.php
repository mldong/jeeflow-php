<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\RepositoryPDO\PdoValue;
use Jeeflow\RepositoryPDO\SqlPaging;
use PHPUnit\Framework\TestCase;

/**
 * issues/67：LIMIT/OFFSET 内联整数，禁止占位符绑定
 */
class SqlPagingTest extends TestCase
{
    public function testClauseInlinesIntegers(): void
    {
        $this->assertSame(' LIMIT 5 OFFSET 0', SqlPaging::clause(5, 0));
        $this->assertSame(' LIMIT 10 OFFSET 20', SqlPaging::clause(10, 20));
    }

    public function testClauseClampsInvalidValues(): void
    {
        $this->assertSame(' LIMIT 1 OFFSET 0', SqlPaging::clause(0, -3));
        $this->assertSame(' LIMIT 1 OFFSET 0', SqlPaging::clause(-8, -1));
    }

    public function testStrIdCastsIntAndKeepsNull(): void
    {
        $this->assertSame('1001', PdoValue::strId(1001));
        $this->assertSame('1001', PdoValue::strId('1001'));
        $this->assertNull(PdoValue::strId(null));
        $this->assertNull(PdoValue::strId(''));
    }
}
