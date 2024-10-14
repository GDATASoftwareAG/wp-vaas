<?php

namespace Gdatacyberdefenseag\GdataAntivirus\Vaas;

use Gdatacyberdefenseag\GdataAntivirus\Infrastructure\FileSystem\IGdataAntivirusFileSystem;
use Gdatacyberdefenseag\GdataAntivirus\PluginPage\AdminNotices;
use Psr\Log\LoggerInterface;
use VaasSdk\Vaas;
use VaasSdk\Authentication\ClientCredentialsGrantAuthenticator;
use VaasSdk\Authentication\ResourceOwnerPasswordGrantAuthenticator;
use VaasSdk\Message\Verdict;
use VaasSdk\VaasOptions as VaasParameters;

if (! class_exists('ScanClient')) {
	class ScanClient {
		private Vaas $vaas;
		private LoggerInterface $logger;
		private VaasOptions $vaas_options;
		private IGdataAntivirusFileSystem $file_system;
		private AdminNotices $admin_notices;

		public function __construct(
			LoggerInterface $logger,
			VaasOptions $vaas_options,
			IGdataAntivirusFileSystem $file_system,
			AdminNotices $admin_notices
		) {
			$logger->info('ScanClient::__construct');
			$this->logger = $logger;
			$this->vaas_options = $vaas_options;
			$this->file_system = $file_system;
			$this->admin_notices = $admin_notices;

			try {
				$this->Connect();
			} catch (\Exception $e) {
				$this->admin_notices->add_notice($e->getMessage());
				$this->logger->error("VaaS connection failed. Please verify if the VaaS-Url is correct.");
				return;
			}
			$plugin_upload_scan_enabled = (bool) get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_plugin_upload_scan_enabled', true);
			$media_upload_scan_enabled  = (bool) get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_media_upload_scan_enabled', true);
			// We don't need to add the filters if both plugin and media upload scan are disabled.
			if ($plugin_upload_scan_enabled === true || $media_upload_scan_enabled === true) {
				add_filter('wp_handle_upload_prefilter', array( $this, 'scan_single_upload' ));
				add_filter('wp_handle_sideload_prefilter', array( $this, 'scan_single_upload' ));
			}

			$comment_scan_enabled  = (bool) get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_comment_scan_enabled', true);
			$pingback_scan_enabled = (bool) get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_pingback_scan_enabled', true);
			// We don't need to add the filter if both comment and pingback scan are disabled.
			if ($comment_scan_enabled === true || $pingback_scan_enabled === true) {
				add_filter('preprocess_comment', array( $this, 'scan_comment' ));
			}

			$post_scan_enabled = (bool) get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_post_scan_enabled', true);
			if ($post_scan_enabled === true) {
				add_filter('wp_insert_post_data', array( $this, 'scan_post' ), 10, 3);
			}
		}

		public function connect() {
			$options    = $this->vaas_options->get_options();
			$this->vaas = new Vaas($options['vaas_url'], $this->logger, new VaasParameters(false, false));
			if (! $this->vaas_options->credentials_configured()) {
				return;
			}
			if ($options['authentication_method'] == 'ResourceOwnerPasswordGrant') {
				$resource_owner_password_grant_authenticator = new ResourceOwnerPasswordGrantAuthenticator(
					'wordpress-customer',
					$options['username'],
					$options['password'],
					$options['token_endpoint']
				);
				$this->vaas->connect($resource_owner_password_grant_authenticator->getToken());
			} else {
				$client_credentials_grant_authenticator = new ClientCredentialsGrantAuthenticator(
					$options['client_id'],
					$options['client_secret'],
					$options['token_endpoint']
				);
				$this->vaas->connect($client_credentials_grant_authenticator->getToken());
			}
		}

		public function scan_post( $data, $postdata, $unsanitized_postarr ) {
			$data = wp_unslash($unsanitized_postarr);
			if (empty($data['post_content'])) {
				return $data;
			}

			$post_scan_enabled = (bool) get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_post_scan_enabled', true);
			if ($post_scan_enabled === false) {
				return $data;
			}

			if (empty($postdata['post_content'])) {
				return $data;
			}

			$post_content = wp_unslash($postdata['post_content']);
			$stream       = $this->file_system->get_resource_stream_from_string($post_content);

			$verdict = $this->vaas->ForStream($stream);
			$this->logger->debug(var_export($verdict, true));
			 // phpcs:ignore
			if (\VaasSdk\Message\Verdict::MALICIOUS === $verdict->Verdict) {
				$this->logger->debug('gdata-antivirus: virus found in post');
				wp_die(esc_html__('virus found', 'gdata-antivirus'));
			}
			return $postdata;
		}

		public function scan_comment( $commentdata ) {
			$comment_scan_enabled  = (bool) get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_comment_scan_enabled', true);
			$pingback_scan_enabled = (bool) get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_pingback_scan_enabled', true);

			$comment_scan_enabled = get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_comment_scan_enabled', true);
			if ($comment_scan_enabled === false) {
				return $commentdata;
			}

			if (empty($commentdata['comment_content'])) {
				return $commentdata;
			}

			/**
			 * If this is a comment and the comment scan is disabled, we don't need to scan the comment.
			 * 'comment_type' - 'pingback', 'trackback', or empty for regular comments see:
			 * https:// developer.wordpress.org/reference/hooks/preprocess_comment/
			 */
			if (empty($commentdata['comment_type']) && $comment_scan_enabled === false) {
				return $commentdata;
				// If this is a pingback and the pingback scan is disabled, we don't need to scan the comment.
			} elseif (! empty($commentdata['comment_type']) && $pingback_scan_enabled === false) {
				return $commentdata;
			}

			$commend_content = wp_unslash($commentdata['comment_content']);
			$stream          = $this->file_system->get_resource_stream_from_string($commend_content);

			$verdict = $this->vaas->ForStream($stream);
			$this->logger->debug(var_export($verdict, true));
			 // phpcs:ignore
			if (\VaasSdk\Message\Verdict::MALICIOUS === $verdict->Verdict) {
				$this->logger->debug('gdata-antivirus: virus found in comment');
				wp_die(esc_html__('virus found', 'gdata-antivirus'));
			}
			return $commentdata;
		}

		public function scan_single_upload( $file ) {
			$plugin_upload_scan_enabled = get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_plugin_upload_scan_enabled', true);
			$media_upload_scan_enabled  = get_option('gdatacyberdefenseag_antivirus_options_on_demand_scan_media_upload_scan_enabled', true);

			/**
			 * When this is a plugin uplaod but the plugin upload scan is disabled,
			 * we don't need to scan the file.
			 */
			$is_plugin_uplad = false;

			$action =  sanitize_key($_REQUEST['action'] ?? '');
			$nonce = wp_unslash($_REQUEST['_wpnonce'] ?? $_REQUEST['nonce']);
			if ($action === 'upload-plugin') {
				if (wp_verify_nonce($nonce, $action) === false) {
					return $file;
				}
				$is_plugin_uplad = true;
				if ($plugin_upload_scan_enabled === false) {
					return $file;
				}
			} elseif (wp_verify_nonce($nonce, 'media-form') === false) {
				return $file;
			}

			/**
			 * When this is a media upload(not a plugin upload) but the media upload scan is disabled,
			 * we don't need to scan the file.
			 */
			if ($is_plugin_uplad === false) {
				if ($media_upload_scan_enabled === false) {
					return $file;
				}
			}

			$verdict = $this->scan_file($file['tmp_name']);
			if (\VaasSdk\Message\Verdict::MALICIOUS === $verdict) {
				$file['error'] = __('virus found', 'gdata-antivirus');
			}
			return $file;
		}

		public function scan_file( $file_path ): Verdict {
			try {
				$verdict = $this->vaas->ForFile($file_path)->Verdict;
			} catch (\Exception $e) {
				$this->logger->debug($e->getMessage());
				return Verdict::UNKNOWN;
			}
			$this->logger->debug(
				'gdata-antivirus: verdict for file ' . $file_path . ': ' . var_export($verdict, true)
			);
			return $verdict;
		}
	}
}
