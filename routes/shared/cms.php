<?php

declare(strict_types=1);

use App\Modules\Cms\Http\Controllers\Admin\BannerController;
use App\Modules\Cms\Http\Controllers\Admin\BlogCategoryController;
use App\Modules\Cms\Http\Controllers\Admin\BlogPostController;
use App\Modules\Cms\Http\Controllers\Admin\ContactSubmissionController;
use App\Modules\Cms\Http\Controllers\Admin\FaqCategoryController;
use App\Modules\Cms\Http\Controllers\Admin\FaqController;
use App\Modules\Cms\Http\Controllers\Admin\MediaController;
use App\Modules\Cms\Http\Controllers\Admin\MenuController;
use App\Modules\Cms\Http\Controllers\Admin\PageController;
use App\Modules\Cms\Http\Controllers\Admin\PageSectionController;
use App\Modules\Cms\Http\Controllers\Admin\TagController;
use App\Modules\Cms\Http\Controllers\Admin\TestimonialController;
use App\Modules\Cms\Http\Controllers\Public\ContactSubmissionController as PublicContactController;
use App\Modules\Cms\Http\Controllers\Public\ContentMarketingController;
use App\Modules\Cms\Http\Controllers\Public\NavigationController;
use App\Modules\Cms\Http\Controllers\Public\PageController as PublicPageController;
use Illuminate\Support\Facades\Route;

/*
| CMS routes (spec §24.10), registered once per scope with the same URIs:
| routes/landlord/cms.php (landlord.public / landlord.admin, no feature
| key) and routes/tenant/cms.php (tenant.public / tenant.admin, with
| feature:content_marketing on the blog, FAQs and testimonials).
|
| @param  list<string>  $marketing  middleware of the content-marketing routes
*/

return static function (string $publicGroup, string $adminGroup, string $name, array $marketing): void {
    Route::middleware($publicGroup)->name($name.'.cms.')->group(static function () use ($marketing): void {
        Route::get('cms/home', [PublicPageController::class, 'home'])->name('home');
        Route::get('cms/pages/{slug}', [PublicPageController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('pages.show');
        Route::get('cms/menus/{key}', [NavigationController::class, 'menu'])->where('key', '[a-z0-9_-]+')->name('menus.show');
        Route::get('cms/banners', [NavigationController::class, 'banners'])->name('banners.index');
        Route::post('contact', [PublicContactController::class, 'store'])->middleware('throttle:auth-sensitive')->name('contact');

        Route::middleware($marketing)->group(static function (): void {
            Route::get('cms/blog-categories', [ContentMarketingController::class, 'blogCategories'])->name('blog-categories.index');
            Route::get('cms/blog-posts', [ContentMarketingController::class, 'blogPosts'])->name('blog-posts.index');
            Route::get('cms/blog-posts/{slug}', [ContentMarketingController::class, 'blogPost'])->where('slug', '[a-z0-9-]+')->name('blog-posts.show');
            Route::get('cms/tags', [ContentMarketingController::class, 'tags'])->name('tags.index');
            Route::get('cms/faq-categories', [ContentMarketingController::class, 'faqCategories'])->name('faq-categories.index');
            Route::get('cms/faqs', [ContentMarketingController::class, 'faqs'])->name('faqs.index');
            Route::get('cms/testimonials', [ContentMarketingController::class, 'testimonials'])->name('testimonials.index');
        });
    });

    Route::middleware($adminGroup)->prefix('admin/cms')->name($name.'.cms.admin.')->group(static function () use ($marketing): void {
        Route::get('pages', [PageController::class, 'index'])->name('pages.index');
        Route::post('pages', [PageController::class, 'store'])->name('pages.store');
        Route::get('pages/{page}', [PageController::class, 'show'])->whereNumber('page')->name('pages.show');
        Route::patch('pages/{page}', [PageController::class, 'update'])->whereNumber('page')->name('pages.update');
        Route::delete('pages/{page}', [PageController::class, 'destroy'])->whereNumber('page')->name('pages.destroy');
        Route::post('pages/{page}/publish', [PageController::class, 'publish'])->whereNumber('page')->name('pages.publish');
        Route::post('pages/{page}/unpublish', [PageController::class, 'unpublish'])->whereNumber('page')->name('pages.unpublish');
        Route::post('pages/{page}/set-homepage', [PageController::class, 'setHomepage'])->whereNumber('page')->name('pages.set-homepage');
        Route::put('pages/{page}/sections', [PageSectionController::class, 'sync'])->whereNumber('page')->name('pages.sections');
        Route::post('pages/{page}/image', [MediaController::class, 'pageImage'])->whereNumber('page')->name('pages.image');
        Route::post('pages/{page}/media', [MediaController::class, 'pageSectionMedia'])->whereNumber('page')->name('pages.media');

        Route::get('menus', [MenuController::class, 'index'])->name('menus.index');
        Route::post('menus', [MenuController::class, 'store'])->name('menus.store');
        Route::patch('menus/{menu}', [MenuController::class, 'update'])->whereNumber('menu')->name('menus.update');
        Route::delete('menus/{menu}', [MenuController::class, 'destroy'])->whereNumber('menu')->name('menus.destroy');
        Route::put('menus/{menu}/items', [MenuController::class, 'syncItems'])->whereNumber('menu')->name('menus.items');

        Route::get('banners', [BannerController::class, 'index'])->name('banners.index');
        Route::post('banners', [BannerController::class, 'store'])->name('banners.store');
        Route::patch('banners/{banner}', [BannerController::class, 'update'])->whereNumber('banner')->name('banners.update');
        Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->whereNumber('banner')->name('banners.destroy');
        Route::post('banners/{banner}/image', [MediaController::class, 'bannerImage'])->whereNumber('banner')->name('banners.image');

        Route::get('contact-submissions', [ContactSubmissionController::class, 'index'])->name('contact-submissions.index');
        Route::get('contact-submissions/{submission}', [ContactSubmissionController::class, 'show'])->whereNumber('submission')->name('contact-submissions.show');
        Route::post('contact-submissions/{submission}/archive', [ContactSubmissionController::class, 'archive'])->whereNumber('submission')->name('contact-submissions.archive');
        Route::delete('contact-submissions/{submission}', [ContactSubmissionController::class, 'destroy'])->whereNumber('submission')->name('contact-submissions.destroy');

        Route::middleware($marketing)->group(static function (): void {
            Route::get('blog-categories', [BlogCategoryController::class, 'index'])->name('blog-categories.index');
            Route::post('blog-categories', [BlogCategoryController::class, 'store'])->name('blog-categories.store');
            Route::patch('blog-categories/{category}', [BlogCategoryController::class, 'update'])->whereNumber('category')->name('blog-categories.update');
            Route::delete('blog-categories/{category}', [BlogCategoryController::class, 'destroy'])->whereNumber('category')->name('blog-categories.destroy');

            Route::get('blog-posts', [BlogPostController::class, 'index'])->name('blog-posts.index');
            Route::post('blog-posts', [BlogPostController::class, 'store'])->name('blog-posts.store');
            Route::get('blog-posts/{post}', [BlogPostController::class, 'show'])->whereNumber('post')->name('blog-posts.show');
            Route::patch('blog-posts/{post}', [BlogPostController::class, 'update'])->whereNumber('post')->name('blog-posts.update');
            Route::delete('blog-posts/{post}', [BlogPostController::class, 'destroy'])->whereNumber('post')->name('blog-posts.destroy');
            Route::post('blog-posts/{post}/publish', [BlogPostController::class, 'publish'])->whereNumber('post')->name('blog-posts.publish');
            Route::post('blog-posts/{post}/unpublish', [BlogPostController::class, 'unpublish'])->whereNumber('post')->name('blog-posts.unpublish');
            Route::post('blog-posts/{post}/image', [MediaController::class, 'postImage'])->whereNumber('post')->name('blog-posts.image');

            Route::get('tags', [TagController::class, 'index'])->name('tags.index');
            Route::delete('tags/{tag}', [TagController::class, 'destroy'])->whereNumber('tag')->name('tags.destroy');

            Route::get('faq-categories', [FaqCategoryController::class, 'index'])->name('faq-categories.index');
            Route::post('faq-categories', [FaqCategoryController::class, 'store'])->name('faq-categories.store');
            Route::patch('faq-categories/{category}', [FaqCategoryController::class, 'update'])->whereNumber('category')->name('faq-categories.update');
            Route::delete('faq-categories/{category}', [FaqCategoryController::class, 'destroy'])->whereNumber('category')->name('faq-categories.destroy');

            Route::put('faqs/reorder', [FaqController::class, 'reorder'])->name('faqs.reorder');
            Route::get('faqs', [FaqController::class, 'index'])->name('faqs.index');
            Route::post('faqs', [FaqController::class, 'store'])->name('faqs.store');
            Route::patch('faqs/{faq}', [FaqController::class, 'update'])->whereNumber('faq')->name('faqs.update');
            Route::delete('faqs/{faq}', [FaqController::class, 'destroy'])->whereNumber('faq')->name('faqs.destroy');

            Route::get('testimonials', [TestimonialController::class, 'index'])->name('testimonials.index');
            Route::post('testimonials', [TestimonialController::class, 'store'])->name('testimonials.store');
            Route::patch('testimonials/{testimonial}', [TestimonialController::class, 'update'])->whereNumber('testimonial')->name('testimonials.update');
            Route::delete('testimonials/{testimonial}', [TestimonialController::class, 'destroy'])->whereNumber('testimonial')->name('testimonials.destroy');
            Route::post('testimonials/{testimonial}/image', [MediaController::class, 'testimonialImage'])->whereNumber('testimonial')->name('testimonials.image');
        });
    });
};
