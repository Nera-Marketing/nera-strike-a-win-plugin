<?php
/**
 * Russian override of the active theme's own `woocommerce/myaccount/
 * form-edit-account.php` — served only when
 * `Nera_SAW_Language::override_theme_account_template()` swaps it in, via
 * WooCommerce's own `woocommerce_locate_template` filter, strictly when
 * the request has resolved to Russian. English keeps using the theme's
 * own file untouched.
 *
 * Every line below is an unmodified copy of that theme file except the two
 * marked "RUSSIAN OVERRIDE" — "Personal Information" and "Password change",
 * literal English baked directly into the markup, calling no translation
 * function this plugin's `gettext` filters could otherwise intercept (see
 * `Nera_SAW_Language::translate_theme_account_strings()`'s own docblock).
 * Everything else on this page already translates the normal way and did
 * not need a copy of its own.
 *
 * KEEPING THIS IN STEP WITH THE THEME'S OWN FILE
 * ------------------------------------------------
 * A future change to the theme's `form-edit-account.php` will not reach a
 * Russian request until this copy is updated to match — the same
 * trade-off `Nera_SAW_Standalone_Chrome::unoverride_template()`'s own
 * docblock already names for the templates it swaps the same way.
 *
 * @package Nera_Strikeawin
 */

defined('ABSPATH') || exit();

do_action('woocommerce_before_edit_account_form');
?>

<div class="nera-edit-account">

  <!-- Page Header -->
  <div class="mb-8">
    <a href="<?php echo esc_url(wc_get_account_endpoint_url('dashboard')); ?>"
       class="lg:hidden inline-flex items-center text-sm font-medium text-gray-600 hover:text-primary transition-colors mb-4">
      <span class="material-symbols-outlined text-base mr-1">arrow_back</span>
      <?php esc_html_e('Back to Dashboard', 'nera-competitions-standard'); ?>
    </a>

    <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 flex items-center gap-3 mb-2">
      <span class="material-symbols-outlined text-primary text-3xl sm:text-4xl">person</span>
      <?php esc_html_e('Account details', 'woocommerce'); ?>
    </h2>
    <p class="text-gray-600">
      <?php esc_html_e(
        'Update your account information and password',
        'nera-competitions-standard',
      ); ?>
    </p>
  </div>

  <form class="woocommerce-EditAccountForm edit-account" action="" method="post" <?php do_action(
    'woocommerce_edit_account_form_tag',
  ); ?>>

    <?php do_action('woocommerce_edit_account_form_start'); ?>

    <!-- Personal Information Card -->
    <div class="bg-surface rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">
      <div class="flex items-center gap-3 mb-6 pb-4 border-b border-gray-200">
        <div class="w-10 h-10 bg-gradient-to-br from-primary to-primary rounded-lg flex items-center justify-center">
          <span class="material-symbols-outlined text-white text-xl">badge</span>
        </div>
        <h3 class="text-xl font-bold text-gray-900">Личная информация</h3>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <p class="woocommerce-form-row woocommerce-form-row--first form-row ">
          <label for="account_first_name" class="block text-sm font-semibold text-gray-700 mb-2">
            <?php esc_html_e('First name', 'woocommerce'); ?>&nbsp;
            <span class="required text-danger">*</span>
          </label>
          <input type="text"
                 class="woocommerce-Input woocommerce-Input--text input-text w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                 name="account_first_name"
                 id="account_first_name"
                 autocomplete="given-name"
                 value="<?php echo esc_attr($user->first_name); ?>" />
        </p>

        <p class="woocommerce-form-row woocommerce-form-row--last form-row">
          <label for="account_last_name" class="block text-sm font-semibold text-gray-700 mb-2">
            <?php esc_html_e('Last name', 'woocommerce'); ?>&nbsp;
            <span class="required text-danger">*</span>
          </label>
          <input type="text"
                 class="woocommerce-Input woocommerce-Input--text input-text w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                 name="account_last_name"
                 id="account_last_name"
                 autocomplete="family-name"
                 value="<?php echo esc_attr($user->last_name); ?>" />
        </p>
      </div>

      <div class="mt-6">
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
          <label for="account_display_name" class="block text-sm font-semibold text-gray-700 mb-2">
            <?php esc_html_e('Display name', 'woocommerce'); ?>&nbsp;
            <span class="required text-danger">*</span>
          </label>
          <input type="text"
                 class="woocommerce-Input woocommerce-Input--text input-text w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                 name="account_display_name"
                 id="account_display_name"
                 value="<?php echo esc_attr($user->display_name); ?>" />
          <span class="text-sm text-gray-500 mt-1 block">
            <em><?php esc_html_e(
              'This will be how your name will be displayed in the account section and in reviews',
              'woocommerce',
            ); ?></em>
          </span>
        </p>
      </div>

      <div class="mt-6">
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
          <label for="account_email" class="block text-sm font-semibold text-gray-700 mb-2">
            <?php esc_html_e('Email address', 'woocommerce'); ?>&nbsp;
            <span class="required text-danger">*</span>
          </label>
          <input type="email"
                 class="woocommerce-Input woocommerce-Input--email input-text w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                 name="account_email"
                 id="account_email"
                 autocomplete="email"
                 value="<?php echo esc_attr($user->user_email); ?>" />
        </p>
      </div>
    </div>

    <?php
      /**
       * Hook where additional fields should be rendered.
       *
       * @since 8.7.0
       */
      do_action('woocommerce_edit_account_form_fields');
    ?>

    <!-- Password Change Card -->
    <div class="bg-surface rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">
      <div class="flex items-center gap-3 mb-6 pb-4 border-b border-gray-200">
        <div class="w-10 h-10 bg-gradient-to-br from-warning to-warning rounded-lg flex items-center justify-center">
          <span class="material-symbols-outlined text-white text-xl">lock</span>
        </div>
        <h3 class="text-xl font-bold text-gray-900">Смена пароля</h3>
      </div>

      <p class="text-sm text-gray-600 mb-6">
        <?php esc_html_e(
          'Leave blank to keep your current password',
          'nera-competitions-standard',
        ); ?>
      </p>

      <div class="space-y-6">
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
          <label for="password_current" class="block text-sm font-semibold text-gray-700 mb-2">
            <?php esc_html_e('Current password (leave blank to leave unchanged)', 'woocommerce'); ?>
          </label>
          <input type="password"
                 class="woocommerce-Input woocommerce-Input--password input-text w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                 name="password_current"
                 id="password_current"
                 autocomplete="off" />
        </p>

        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
          <label for="password_1" class="block text-sm font-semibold text-gray-700 mb-2">
            <?php esc_html_e('New password (leave blank to leave unchanged)', 'woocommerce'); ?>
          </label>
          <input type="password"
                 class="woocommerce-Input woocommerce-Input--password input-text w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                 name="password_1"
                 id="password_1"
                 autocomplete="off" />
        </p>

        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
          <label for="password_2" class="block text-sm font-semibold text-gray-700 mb-2">
            <?php esc_html_e('Confirm new password', 'woocommerce'); ?>
          </label>
          <input type="password"
                 class="woocommerce-Input woocommerce-Input--password input-text w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                 name="password_2"
                 id="password_2"
                 autocomplete="off" />
        </p>
      </div>
    </div>

    <?php do_action('woocommerce_edit_account_form'); ?>

    <!-- Action Buttons -->
    <div class="flex flex-col sm:flex-row gap-3">
          <?php wp_nonce_field('save_account_details', 'save-account-details-nonce'); ?>
          <button type="submit"
                  class="woocommerce-Button button !inline-flex items-center justify-center gap-2 px-8 py-4 bg-gradient-to-r from-primary to-primary text-white font-semibold rounded-xl hover:opacity-90 transition-all shadow-sm hover:shadow-md w-full sm:w-auto"
                  name="save_account_details"
                  value="<?php esc_attr_e('Save changes', 'woocommerce'); ?>">
            <span class="material-symbols-outlined text-xl">save</span>
            <?php esc_html_e('Save changes', 'woocommerce'); ?>
          </button>
          <input type="hidden" name="action" value="save_account_details" />

      <a href="<?php echo esc_url(wc_get_account_endpoint_url('dashboard')); ?>"
         class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-surface border-2 border-gray-200 text-gray-700 font-semibold rounded-xl hover:border-primary hover:text-primary transition-all w-full sm:w-auto">
        <span class="material-symbols-outlined text-xl">cancel</span>
        <?php esc_html_e('Cancel', 'nera-competitions-standard'); ?>
      </a>
    </div>

    <?php do_action('woocommerce_edit_account_form_end'); ?>
  </form>

  <!-- Deactivate account (separate POST; does not submit with Save changes) -->
  <div class="mt-10 pt-8 border-t border-danger-bg">
    <div class="bg-danger-bg/80 rounded-2xl border border-danger-border/80 shadow-sm p-6">
      <div class="flex items-start gap-3 mb-4">
        <div class="w-10 h-10 shrink-0 bg-gradient-to-br from-danger to-danger-text rounded-lg flex items-center justify-center">
          <span class="material-symbols-outlined text-white text-xl">warning</span>
        </div>
        <div class="min-w-0 flex-1">
          <h3 class="text-lg font-bold text-gray-900">
            <?php esc_html_e('Deactivate account', 'nera-competitions-standard'); ?>
          </h3>
          <p class="text-sm text-gray-600 mt-1">
            <?php esc_html_e(
              'Permanently delete your account and personal data associated with it. This cannot be undone.',
              'nera-competitions-standard',
            ); ?>
          </p>
        </div>
      </div>

      <form
        id="nera-deactivate-account-form"
        class="nera-deactivate-account-form"
        method="post"
        action="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>"
      >
        <?php wp_nonce_field('nera_deactivate_account', 'nera-deactivate-account-nonce'); ?>
        <input type="hidden" name="action" value="nera_deactivate_account" />
        <input type="hidden" name="nera_deactivate_user_id" value="<?php echo esc_attr(get_current_user_id()); ?>" />

        <button
          type="button"
          id="nera-deactivate-account-open"
          class="inline-flex items-center justify-center gap-2 px-6 py-3.5 w-full sm:w-auto rounded-xl font-semibold text-white bg-gradient-to-r from-danger to-danger-text hover:from-danger-text hover:to-danger-text border border-danger-text/30 shadow-sm hover:shadow-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-2"
        >
          <span class="material-symbols-outlined text-xl">person_off</span>
          <?php esc_html_e('Deactivate Account', 'nera-competitions-standard'); ?>
        </button>
      </form>

      <dialog
        id="nera-deactivate-account-dialog"
        class="nera-deactivate-account-dialog fixed left-1/2 top-1/2 z-[100] max-h-[min(100vh-2rem,90dvh)] max-w-md w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 overflow-hidden rounded-2xl border border-gray-200 bg-surface p-0 shadow-2xl backdrop:bg-black/50"
        aria-labelledby="nera-deactivate-account-dialog-title"
      >
        <div class="flex max-h-[min(100vh-2rem,90dvh)] min-h-0 flex-col overflow-y-auto">
          <div class="p-6 border-b border-gray-100">
            <h4 id="nera-deactivate-account-dialog-title" class="text-lg font-bold text-gray-900 flex items-center gap-2">
              <span class="material-symbols-outlined text-danger">help</span>
              <?php esc_html_e('Delete your account?', 'nera-competitions-standard'); ?>
            </h4>
            <p class="text-sm text-gray-600 mt-2">
              <?php esc_html_e(
                'This will permanently remove your account. If you are sure, click Yes.',
                'nera-competitions-standard',
              ); ?>
            </p>
          </div>
          <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 p-4 bg-gray-50/80 rounded-b-2xl shrink-0">
            <button
              type="button"
              id="nera-deactivate-account-cancel"
              class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl font-semibold border-2 border-gray-200 text-gray-700 bg-white hover:border-gray-300 transition-colors w-full sm:w-auto"
            >
              <?php esc_html_e('Cancel', 'nera-competitions-standard'); ?>
            </button>
            <button
              type="button"
              id="nera-deactivate-account-confirm"
              class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl font-semibold text-white bg-danger hover:bg-danger-text border border-danger-text shadow-sm transition-colors w-full sm:w-auto"
            >
              <?php esc_html_e('Yes', 'nera-competitions-standard'); ?>
            </button>
          </div>
        </div>
      </dialog>

      <script>
        (function () {
          var openBtn = document.getElementById('nera-deactivate-account-open');
          var dlg = document.getElementById('nera-deactivate-account-dialog');
          var cancelBtn = document.getElementById('nera-deactivate-account-cancel');
          var yesBtn = document.getElementById('nera-deactivate-account-confirm');
          var form = document.getElementById('nera-deactivate-account-form');
          if (!openBtn || !dlg || !cancelBtn || !yesBtn || !form) return;

          openBtn.addEventListener('click', function () {
            if (typeof dlg.showModal === 'function') {
              dlg.showModal();
            }
          });

          cancelBtn.addEventListener('click', function () {
            dlg.close();
          });

          yesBtn.addEventListener('click', function () {
            dlg.close();
            form.submit();
          });
        })();
      </script>
    </div>
  </div>

</div>

<?php do_action('woocommerce_after_edit_account_form'); ?>
