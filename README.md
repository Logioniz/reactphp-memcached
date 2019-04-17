Very simple memcached client for reactphp.

Only get/gets and set methods are implemented. If you need some other methods or any functionality, write to issue.

Why I don't use another driver [seregazhuk/php-react-memcached|https://github.com/seregazhuk/php-react-memcached]. The author of that module did not close 5 topics for a long time that were important to me:
1. [Incorrect read message](https://github.com/seregazhuk/php-react-memcached/issues/22)
2. [Incorrect handle big message](https://github.com/seregazhuk/php-react-memcached/issues/21)
3. [Incorrectly reads multiple value at once](https://github.com/seregazhuk/php-react-memcached/issues/20)
4. [Failed when key doesn't exists](https://github.com/seregazhuk/php-react-memcached/issues/19)
5. [Ability to set cutom serialize and deserialize function](https://github.com/seregazhuk/php-react-memcached/issues/18)


See examples in test.php.
