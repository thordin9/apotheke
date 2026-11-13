<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Filters\FormFieldsFilter;
use Apotheke\Core\Http\FormFields;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/** A form write takes one value per field; only the forms' list fields take lists. */
final class FormFieldsTest extends CIUnitTestCase
{
    public function testOnlyTheListFieldsTakeLists(): void
    {
        $rule = new FormFields();
        $this->assertNull($rule->invalid([]));
        $this->assertNull($rule->invalid(['id' => '4', 'name' => 'Lamp', 'notes' => '']));
        $this->assertNull($rule->invalid(['ids' => ['1', '2'], 'perms' => ['edit'], 'col' => [0 => 'name', 3 => ''], 'action' => 'move']));
        $this->assertSame('id', $rule->invalid(['id' => ['2']]));
        $this->assertSame('name', $rule->invalid(['id' => '1', 'name' => ['x' => 'y']]));
        $this->assertSame('notes', $rule->invalid(['notes' => []]), 'an empty list too');
        $this->assertNull($rule->invalid(['ids' => [['1']]]), 'a list field\'s controller reads the values it takes');
        $this->assertSame('0', $rule->invalid([0 => ['x']]));
        $this->assertSame('Each field takes one value (name).', $rule->message('name'));
        $this->assertSame('Each field takes one value (field).', $rule->message('<b>'));
    }

    public function testTheFilterRefusesWritesOnly(): void
    {
        $filter = new FormFieldsFilter();
        $this->assertNull($filter->before($this->request('GET', ['name' => ['x']])), 'GET filters take lists');
        $this->assertNull($filter->before($this->request('POST', ['name' => 'x', 'ids' => ['1']])));
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            try {
                $filter->before($this->request($method, ['id' => ['7'], 'name' => 'x']));
                $this->fail($method);
            } catch (UserError $e) {
                $this->assertSame([422, 'Each field takes one value (id).'], [$e->getCode(), $e->getMessage()], $method);
            }
        }
    }

    /** @param array<string,mixed> $post */
    private function request(string $method, array $post): IncomingRequest
    {
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'save'), null, new UserAgent());
        $request = $request->withMethod($method);
        $request->setGlobal('post', $post);
        $request->setGlobal('server', ['REQUEST_METHOD' => $method]);

        return $request;
    }
}
