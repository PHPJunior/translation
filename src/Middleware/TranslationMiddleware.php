<?php namespace Waavi\Translation\Middleware;

use Closure;
use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\View\Factory as ViewFactory;
use Waavi\Translation\Repositories\LanguageRepository;
use Waavi\Translation\UriLocalizer;

class TranslationMiddleware
{
    /**
     *  Constructor
     *
     *  @param  Waavi\Translation\UriLocalizer                      $uriLocalizer
     *  @param  Waavi\Translation\Repositories\LanguageRepository   $languageRepository
     *  @param  Illuminate\Config\Repository                        $config                 Laravel config
     *  @param  Illuminate\View\Factory                             $viewFactory
     *  @param  Illuminate\Foundation\Application                   $app
     */
    public function __construct(UriLocalizer $uriLocalizer, LanguageRepository $languageRepository, Config $config, ViewFactory $viewFactory, Application $app)
    {
        $this->uriLocalizer       = $uriLocalizer;
        $this->languageRepository = $languageRepository;
        $this->config             = $config;
        $this->viewFactory        = $viewFactory;
        $this->app                = $app;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // Ignores all non GET requests:
        if ($request->method() !== 'GET') {
            return $next($request);
        }

        $currentUrl    = $request->getUri();
        $uriLocale     = $this->uriLocalizer->getLocaleFromUrl($currentUrl);
        $defaultLocale = $this->config->get('app.locale');

        // If a locale was set in the url:
        if ($uriLocale) {
            $currentLanguage     = $this->languageRepository->findByLocale($uriLocale);
            $selectableLanguages = $this->languageRepository->allExcept($uriLocale);
            $altLocalizedUrls    = [];
            foreach ($selectableLanguages as $lang) {
                $altLocalizedUrls[] = [
                    'locale' => $lang->locale,
                    'name'   => $lang->name,
                    'url'    => $this->uriLocalizer->localize($currentUrl, $lang->locale),
                ];
            }

            // Set app locale
            $this->app->setLocale($uriLocale);

            // Share language variable with views:
            $this->viewFactory->share('currentLanguage', $currentLanguage);
            $this->viewFactory->share('selectableLanguages', $selectableLanguages);
            $this->viewFactory->share('altLocalizedUrls', $altLocalizedUrls);

            // Set locale in session:
            if ($request->hasSession() && $request->session()->get('locale') !== $uriLocale) {
                $request->session()->put('locale', $uriLocale);
            }
            return $next($request);
        }

        // If no locale was set in the url, check the session locale
        if ($request->hasSession() && $sessionLocale = $request->session()->get('locale')) {
            if ($this->languageRepository->isValidLocale($sessionLocale)) {
                return redirect()->to($this->uriLocalizer->localize($currentUrl, $sessionLocale));
            }
        }

        // If no locale was set in the url, check the browser's locale:
        $browserLocale = substr($request->server('HTTP_ACCEPT_LANGUAGE'), 0, 2);
        if ($this->languageRepository->isValidLocale($browserLocale)) {
            return redirect()->to($this->uriLocalizer->localize($currentUrl, $browserLocale));
        }

        // If not, redirect to the default locale:
        return redirect()->to($this->uriLocalizer->localize($currentUrl, $defaultLocale));
    }
}
