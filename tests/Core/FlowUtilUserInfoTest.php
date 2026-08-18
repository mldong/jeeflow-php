<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Util\FlowUtil;
use PHPUnit\Framework\TestCase;

/**
 * FlowUtil 用户信息注入 + 自动标题 单元测试（issues/73）
 */
class FlowUtilUserInfoTest extends TestCase
{
    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    public function testAddUserInfoToArgs_normalUser(): void
    {
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('getUser')->with('u001')->willReturn([
            'userId' => 'u001', 'realName' => '张三', 'deptId' => 'd01',
            'deptName' => '研发部', 'postId' => 'p01', 'postName' => '工程师',
        ]);
        ServiceContext::put(UserProviderInterface::class, $provider);

        $args = FlowData::create();
        FlowUtil::addUserInfoToArgs('u001', $args);

        $this->assertSame('u001', $args->get(FlowConst::USER_USER_ID));
        $this->assertSame('张三', $args->get(FlowConst::USER_REAL_NAME));
        $this->assertSame('d01', $args->get(FlowConst::USER_DEPT_ID));
        $this->assertSame('研发部', $args->get(FlowConst::USER_DEPT_NAME));
        $this->assertSame('p01', $args->get(FlowConst::USER_POST_ID));
        $this->assertSame('工程师', $args->get(FlowConst::USER_POST_NAME));
    }

    public function testAddUserInfoToArgs_autoIdSkipped(): void
    {
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->never())->method('getUser');
        ServiceContext::put(UserProviderInterface::class, $provider);

        $args = FlowData::create();
        FlowUtil::addUserInfoToArgs(FlowConst::AUTO_ID, $args);
        $this->assertNull($args->get(FlowConst::USER_USER_ID));

        FlowUtil::addUserInfoToArgs(FlowConst::ADMIN_ID, $args);
        $this->assertNull($args->get(FlowConst::USER_USER_ID));
    }

    public function testAddUserInfoToArgs_noProviderSkipped(): void
    {
        $args = FlowData::create();
        FlowUtil::addUserInfoToArgs('u001', $args);
        $this->assertNull($args->get(FlowConst::USER_USER_ID));
    }

    public function testAddUserInfoToArgs_userNotFoundSkipped(): void
    {
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('getUser')->with('unknown')->willReturn(null);
        ServiceContext::put(UserProviderInterface::class, $provider);

        $args = FlowData::create();
        FlowUtil::addUserInfoToArgs('unknown', $args);
        $this->assertNull($args->get(FlowConst::USER_USER_ID));
    }

    public function testAddUserInfoToArgs_fallbackToOperator(): void
    {
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('getUser')->with('u002')->willReturn([
            'userId' => null, 'realName' => null, 'deptId' => null,
            'deptName' => null, 'postId' => null, 'postName' => null,
        ]);
        ServiceContext::put(UserProviderInterface::class, $provider);

        $args = FlowData::create();
        FlowUtil::addUserInfoToArgs('u002', $args);

        $this->assertSame('u002', $args->get(FlowConst::USER_USER_ID));
        $this->assertSame('u002', $args->get(FlowConst::USER_REAL_NAME));
    }

    public function testAddAutoGenTitle_format(): void
    {
        $args = FlowData::create();
        $args->set(FlowConst::USER_REAL_NAME, '张三');
        FlowUtil::addAutoGenTitle('请假', $args);

        $title = $args->get(FlowConst::AUTO_GEN_TITLE);
        $this->assertNotNull($title);
        $this->assertMatchesRegularExpression('/^张三的请假-\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $title);
    }

    public function testAddAutoGenTitle_noRealName(): void
    {
        $args = FlowData::create();
        FlowUtil::addAutoGenTitle('请假', $args);

        $title = $args->get(FlowConst::AUTO_GEN_TITLE);
        $this->assertNotNull($title);
        $this->assertMatchesRegularExpression('/^的请假-\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $title);
    }
}