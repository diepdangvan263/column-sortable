<?php

namespace Kyslik\ColumnSortable;

use Nova\Support\Facades\View;
use Nova\Support\ServiceProvider;

/**
 * Class ColumnSortableServiceProvider
 * @package Kyslik\ColumnSortable
 */
class ColumnSortableServiceProvider extends ServiceProvider
{

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;


    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/columnsortable.php' => config_path('columnsortable.php'),
        ], 'config');

        View::directive('sortablelink', function ($expression) {
            $expression = ($expression[0] === '(') ? substr($expression, 1, -1) : $expression;

            return "<?php echo \Kyslik\ColumnSortable\SortableLink::render(array ({$expression}));?>";
        });
    }


    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/columnsortable.php', 'columnsortable');
    }
}
