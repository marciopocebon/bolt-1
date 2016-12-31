<?php
namespace Bolt\Tests;

use Bolt\AccessControl\Token;
use Bolt\Application;
use Bolt\Configuration as Config;
use Bolt\Legacy\Storage;
use Bolt\Render;
use Bolt\Storage\Entity;
use Bolt\Tests\Mocks\LoripsumMock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

/**
 * Abstract Class that other unit tests can extend, provides generic methods for Bolt tests.
 *
 * @author Ross Riley <riley.ross@gmail.com>
 **/
abstract class BoltUnitTest extends \PHPUnit_Framework_TestCase
{
    private $app;

    protected function resetDb()
    {
        // Make sure we wipe the db file to start with a clean one
        if (is_readable(PHPUNIT_WEBROOT . '/app/database/bolt.db')) {
            unlink(PHPUNIT_WEBROOT . '/app/database/bolt.db');
            copy(PHPUNIT_ROOT . '/resources/db/bolt.db', PHPUNIT_WEBROOT . '/app/database/bolt.db');
        }
    }

    protected function resetConfig()
    {
        $configFiles = [
            'config.yml',
            'contenttypes.yml',
            'menu.yml',
            'permissions.yml',
            'routing.yml',
            'taxonomy.yml',
        ];
        foreach ($configFiles as $configFile) {
            // Make sure we wipe the db file to start with a clean one
            if (is_readable(PHPUNIT_WEBROOT . '/app/config/' . $configFile)) {
                unlink(PHPUNIT_WEBROOT . '/app/config/' . $configFile);
            }
        }
    }

    protected function getApp($boot = true)
    {
        if (!$this->app) {
            $this->app = $this->makeApp();
            $this->app->initialize();

            $verifier = new Config\Validation\Validator($this->app['controller.exception'], $this->app['config'], $this->app['resources']);
            $verifier->checks();

            if ($boot) {
                $this->app->boot();
            }
        }

        return $this->app;
    }

    protected function makeApp()
    {
        $app = new Application();
        $app['path_resolver.root'] = PHPUNIT_WEBROOT;
        $app['path_resolver.paths'] = ['web' => '.'];
        $app['debug'] = false;

        $app['config']->set(
            'general/database',
            [
                'driver'       => 'pdo_sqlite',
                'prefix'       => 'bolt_',
                'user'         => 'test',
                'path'         => PHPUNIT_WEBROOT . '/app/database/bolt.db',
                'wrapperClass' => '\Bolt\Storage\Database\Connection',
            ]
        );
        $app['config']->set('general/canonical', 'bolt.dev');

        return $app;
    }

    protected function rmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    protected function addDefaultUser(Application $app)
    {
        // Check if default user exists before adding
        $existingUser = $app['users']->getUser('admin');
        if (false !== $existingUser) {
            return $existingUser;
        }

        $user = [
            'username'    => 'admin',
            'password'    => 'password',
            'email'       => 'admin@example.com',
            'displayname' => 'Admin',
            'roles'       => ['admin'],
            'enabled'     => true,
        ];

        $app['users']->saveUser(array_merge($app['users']->getEmptyUser(), $user));

        return $user;
    }

    protected function addNewUser($app, $username, $displayname, $role, $enabled = true)
    {
        $user = [
            'username'    => $username,
            'password'    => 'password',
            'email'       => $username . '@example.com',
            'displayname' => $displayname,
            'roles'       => [$role],
            'enabled'     => $enabled,
        ];

        $app['users']->saveUser(array_merge($app['users']->getEmptyUser(), $user));
        $app['users']->users = [];
    }

    protected function getRenderMock(Application $app)
    {
        $render = $this->getMock(Render::class, ['render', 'fetchCachedRequest'], [$app]);
        $render->expects($this->any())
            ->method('fetchCachedRequest')
            ->will($this->returnValue(false));

        return $render;
    }

    protected function checkTwigForTemplate(Application $app, $testTemplate)
    {
        $render = $this->getRenderMock($app);

        $render->expects($this->atLeastOnce())
            ->method('render')
            ->with($this->equalTo($testTemplate))
            ->will($this->returnValue(new Response()));

        $app['render'] = $render;
    }

    protected function allowLogin($app)
    {
        $this->addDefaultUser($app);
        $users = $this->getMock('Bolt\Users', ['isEnabled'], [$app]);
        $users->expects($this->any())
            ->method('isEnabled')
            ->will($this->returnValue(true));
        $app['users'] = $users;

        $permissions = $this->getMock('Bolt\AccessControl\Permissions', ['isAllowed'], [$this->getApp()]);
        $permissions->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnValue(true));
        $this->setService('permissions', $permissions);

        $auth = $this->getAccessCheckerMock($app);
        $auth->expects($this->any())
            ->method('isValidSession')
            ->will($this->returnValue(true));

        $app['access_control'] = $auth;
    }

    /**
     * @param \Silex\Application $app
     * @param array              $functions Defaults to ['isValidSession']
     *
     * @return \Bolt\AccessControl\AccessChecker|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getAccessCheckerMock($app, $functions = ['isValidSession'])
    {
        $accessCheckerMock = $this->getMock(
            'Bolt\AccessControl\AccessChecker',
            $functions,
            [
                $app['storage.lazy'],
                $app['request_stack'],
                $app['session'],
                $app['dispatcher'],
                $app['logger.flash'],
                $app['logger.system'],
                $app['permissions'],
                $app['randomgenerator'],
                $app['access_control.cookie.options'],
            ]
        );

        return $accessCheckerMock;
    }

    /**
     * @param \Silex\Application $app
     * @param array              $functions Defaults to ['login']
     *
     * @return \PHPUnit_Framework_MockObject_MockObject A mocked \Bolt\AccessControl\Login
     */
    protected function getLoginMock($app, $functions = ['login'])
    {
        $loginMock = $this->getMock('Bolt\AccessControl\Login', $functions, [$app]);

        return $loginMock;
    }

    protected function getCacheMock($path = null)
    {
        $app = $this->getApp();
        if ($path === null) {
            $path = $app['resources']->getPath('cache');
        }

        $params = [
            $path,
            \Bolt\Cache::EXTENSION,
            0002,
            $app['filesystem'],
        ];

        $cache = $this->getMock('Bolt\Cache', ['flushAll'], $params);

        return $cache;
    }

    protected function removeCSRF($app)
    {
        // Symfony forms need a CSRF token so we have to mock this too
        $csrf = $this->getMock(CsrfTokenManager::class, ['isTokenValid', 'getToken'], [], '', false);
        $csrf->expects($this->any())
            ->method('isTokenValid')
            ->will($this->returnValue(true));

        $csrf->expects($this->any())
            ->method('getToken')
            ->will($this->returnValue('xyz'));

        $app['form.csrf_provider'] = $csrf;
    }

    protected function addSomeContent()
    {
        $app = $this->getApp();
        $this->addDefaultUser($app);
        $app['config']->set('taxonomy/categories/options', ['news']);
        $prefillMock = new LoripsumMock();
        $app['prefill'] = $prefillMock;

        $storage = new Storage($app);
        $storage->prefill(['showcases', 'pages']);
    }

    /**
     * @param string $key
     * @param mixed  $value
     */
    protected function setService($key, $value)
    {
        $this->getApp()->offsetSet($key, $value);
    }

    protected function getService($key)
    {
        return $this->getApp()->offsetGet($key);
    }

    protected function setSessionUser(Entity\Users $userEntity)
    {
        $tokenEntity = new Entity\Authtoken();
        $tokenEntity->setToken('testtoken');
        $authToken = new Token\Token($userEntity, $tokenEntity);

        $this->getService('session')->set('authentication', $authToken);
    }
}
