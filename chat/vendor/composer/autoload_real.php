<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit53e1a312865c4dc7a4cbc449a3cfccad
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInit53e1a312865c4dc7a4cbc449a3cfccad', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInit53e1a312865c4dc7a4cbc449a3cfccad', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInit53e1a312865c4dc7a4cbc449a3cfccad::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}