document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    /*
    |--------------------------------------------------------------------------
    | ŞİFRE GÖSTER / GİZLE
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('.mhm-toggle-password')
        .forEach(function (button) {

            button.addEventListener('click', function () {

                const targetId = button.getAttribute('data-target');
                const input = document.getElementById(targetId);

                if (!input) {
                    return;
                }

                const isVisible = input.type === 'text';

                input.type = isVisible
                    ? 'password'
                    : 'text';

                button.textContent = isVisible
                    ? 'Göster'
                    : 'Gizle';
            });
        });


    /*
    |--------------------------------------------------------------------------
    | YÖNETİM PANELİ BÖLÜMLERİ
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('.mhm-admin-section-toggle')
        .forEach(function (button) {

            button.addEventListener('click', function () {

                const section = button.closest(
                    '.mhm-admin-section'
                );

                if (!section) {
                    return;
                }

                const collapsed = section.classList.toggle(
                    'is-collapsed'
                );

                button.setAttribute(
                    'aria-expanded',
                    collapsed ? 'false' : 'true'
                );

                const symbol = button.querySelector(
                    '.mhm-admin-toggle-symbol'
                );

                if (symbol) {
                    symbol.textContent = collapsed ? '+' : '−';
                }
            });
        });
});


/*
|--------------------------------------------------------------------------
| MAĞAZA GÜNCELLEME
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function () {

    const logoInput = document.getElementById(
        'mhm_logo_file'
    );

    const logoName = document.querySelector(
        '.mhm-logo-file-name'
    );

    if (logoInput && logoName) {

        logoInput.addEventListener(
            'change',
            function () {

                if (
                    logoInput.files &&
                    logoInput.files.length
                ) {
                    logoName.textContent =
                        logoInput.files[0].name;
                } else {
                    logoName.textContent =
                        logoName.getAttribute(
                            'data-empty-text'
                        ) || 'Henüz dosya seçilmedi.';
                }
            }
        );
    }

    document
        .querySelectorAll('.mhm-store-update-form')
        .forEach(function (form) {

            form.addEventListener(
                'submit',
                function () {

                    const button =
                        form.querySelector(
                            '.mhm-store-submit'
                        );

                    if (!button) {
                        return;
                    }

                    button.disabled = true;
                    button.textContent = 'Gönderiliyor...';
                }
            );
        });
});


/*
|--------------------------------------------------------------------------
| TEKNİK DESTEK
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function () {

    const supportFile = document.getElementById(
        'mhm_support_file'
    );

    const supportFileName = document.querySelector(
        '.mhm-support-file-name'
    );

    if (supportFile && supportFileName) {

        supportFile.addEventListener(
            'change',
            function () {

                if (
                    supportFile.files &&
                    supportFile.files.length
                ) {
                    supportFileName.textContent =
                        supportFile.files[0].name;
                } else {
                    supportFileName.textContent =
                        supportFileName.getAttribute(
                            'data-empty-text'
                        ) || 'Henüz dosya seçilmedi.';
                }
            }
        );
    }

    const supportForm = document.querySelector(
        '.mhm-support-form'
    );

    if (supportForm) {

        supportForm.addEventListener(
            'submit',
            function () {

                const button = supportForm.querySelector(
                    '.mhm-support-submit'
                );

                if (!button) {
                    return;
                }

                button.disabled = true;
                button.textContent = 'Gönderiliyor...';
            }
        );
    }
});


/*
|--------------------------------------------------------------------------
| TASARIM YÜKLE
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function () {

    const designFile = document.getElementById(
        'mhm_design_file'
    );

    const designFileName = document.querySelector(
        '.mhm-design-file-name'
    );

    if (designFile && designFileName) {

        designFile.addEventListener(
            'change',
            function () {

                if (
                    designFile.files &&
                    designFile.files.length
                ) {
                    designFileName.textContent =
                        designFile.files[0].name;
                } else {
                    designFileName.textContent =
                        designFileName.getAttribute(
                            'data-empty-text'
                        ) || 'Henüz dosya seçilmedi.';
                }
            }
        );
    }

    const designForm = document.querySelector(
        '.mhm-design-upload-form'
    );

    if (designForm) {

        designForm.addEventListener(
            'submit',
            function () {

                const button = designForm.querySelector(
                    '.mhm-design-upload-submit'
                );

                if (!button) {
                    return;
                }

                button.disabled = true;
                button.textContent = 'Yükleniyor...';
            }
        );
    }
});


/*
|--------------------------------------------------------------------------
| 2.2.0 — WORDPRESS EDİTÖRÜ / AÇILIR BÖLÜMLER
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function () {

    document
        .querySelectorAll('.mhm-admin-section-toggle')
        .forEach(function (button) {

            button.addEventListener('click', function () {

                window.setTimeout(function () {

                    if (
                        window.tinymce &&
                        typeof window.tinymce.triggerSave === 'function'
                    ) {
                        window.tinymce.triggerSave();
                    }

                }, 60);
            });
        });
});


/*
|--------------------------------------------------------------------------
| 2.2.4 — TASARIM GALERİSİ LIGHTBOX
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function () {

    const triggers = document.querySelectorAll(
        '.mhm-design-lightbox-trigger'
    );

    if (!triggers.length) {
        return;
    }

    const lightbox = document.querySelector(
        '.mhm-design-lightbox'
    );

    if (!lightbox) {
        return;
    }

    const lightboxImage = lightbox.querySelector(
        '.mhm-design-lightbox-image'
    );

    const closeButton = lightbox.querySelector(
        '.mhm-design-lightbox-close'
    );

    if (!lightboxImage) {
        return;
    }

    function openLightbox(trigger) {

        const fullImage = trigger.getAttribute(
            'data-full-image'
        );

        const previewImage = trigger.querySelector('img');

        if (!fullImage) {
            return;
        }

        lightboxImage.src = fullImage;
        lightboxImage.alt = previewImage
            ? previewImage.alt
            : 'Yüklenen tasarım';

        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');

        document.body.classList.add(
            'mhm-design-lightbox-open'
        );

        if (closeButton) {
            closeButton.focus();
        }
    }

    function closeLightbox() {

        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');

        lightboxImage.src = '';
        lightboxImage.alt = '';

        document.body.classList.remove(
            'mhm-design-lightbox-open'
        );
    }

    triggers.forEach(function (trigger) {

        trigger.addEventListener('click', function () {
            openLightbox(trigger);
        });
    });

    if (closeButton) {
        closeButton.addEventListener(
            'click',
            closeLightbox
        );
    }

    lightbox.addEventListener('click', function (event) {

        if (event.target === lightbox) {
            closeLightbox();
        }
    });

    document.addEventListener('keydown', function (event) {

        if (
            event.key === 'Escape' &&
            !lightbox.hidden
        ) {
            closeLightbox();
        }
    });
});


/* =========================================================
   2.2.6 — TASARIM DOSYASI TÜRKÇE DOSYA ADI
   ========================================================= */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mhm-design-file-control input[type="file"]').forEach(function (input) {
        var control = input.closest('.mhm-design-file-control');
        if (!control) return;

        var text = control.querySelector('.mhm-design-file-text');
        if (!text) return;

        input.addEventListener('change', function () {
            var emptyText = text.getAttribute('data-empty-text') || 'Henüz dosya seçilmedi';

            if (input.files && input.files.length) {
                text.textContent = input.files[0].name;
                text.title = input.files[0].name;
            } else {
                text.textContent = emptyText;
                text.removeAttribute('title');
            }
        });
    });
});
