=== Nhooja Himalayan Bank Payment Gateway ===
Contributors: manojtsx
Tags: payment gateway, himalayan bank, ba-book-everything, e-commerce, nepali payment gateway
Requires at least: 6.0
Tested up to: 6.7.1
Stable tag: 1.3.0
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate Himalayan Bank as a payment gateway in your BA Book Everything store for secure transactions.

== Description ==

The Nhooja Himalayan Bank Payment Gateway plugin for BA Book Everything allows you to accept payments directly on your store via Himalayan Bank's secure payment processing system. Customers can make payments for their orders using their credit or debit cards without leaving your site.

This plugin seamlessly integrates with BA Book Everything, providing a straightforward setup process and a secure payment solution. It supports 3D Secure for added security and is compatible with BA Book Everything's checkout process.

Features include:
- Easy integration with BA Book Everything.
- Secure payment processing with 3D Secure support.
- Configuration options for test mode, merchant IDs, and encryption keys.
- Customizable payment success, failure, and cancellation messages.

For detailed setup instructions and configuration options, please refer to the Installation and FAQ sections.

= Privacy notices =

This plugin does not:

* track users by any means;
* send any critical data to external servers;


== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/nexhbp-himalayan-bank-payment-gateway` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the BA Book Everything->Settings->Payments screen to configure the plugin.
4. Enable the Himalayan Bank Payment Gateway and enter your merchant details and encryption keys as provided by Himalayan Bank.

== Frequently Asked Questions ==

= Does this plugin support 3D Secure transactions? =
Yes, the plugin supports 3D Secure for added security during transactions.

= Can I test the payment process before going live? =
Yes, the plugin provides a test mode that you can enable to test transactions before accepting real payments.

= Where do I get my merchant ID and encryption keys? =
Your merchant ID and encryption keys are provided by Himalayan Bank when you register for their payment gateway services.

= What test cards can I use for testing? =
Use the following test cards for testing purposes:
  - Card Name: SUJAN TEST
  - Card Number: 5399 3300 0001 2640, CVV: 734, Expiry: 04/2027
  - Card Number: 4101 4900 0005 7763, CVV: 344, Expiry: 09/2027

= How can I generate merchant keys? =
Follow the steps provided in the following links to generate your merchant keys:
- https://www.devglan.com/online-tools/rsa-encryption-decryption
After generating, provide the public signing and public encryption keys to Himalayan Bank (HBL).

== Changelog ==

= 1.3.0 =
* Fixes bugs with custom success page.

= 1.2.0 =
* *Fixes* - Fixes JavaScript error on admin pages
* *New Feature* - Added support for custom success, failure, and cancellation pages
* *New Feature* - Added support for card fees

= 1.1.4 =
* Compatibility check with WordPress 6.7

= 1.1.3 =
* Fixes bugs with nonce validation.

= 1.1 =
* Adds support for BA Book Everything HPOS (High-performance order storage).
* User interface improvements
* Fixes bugs when payment status is not updated correctly.
* Fixes issues with payment success redirection from payment gateway.

= 1.0 =
* Initial release. Support for secure card payments through Himalayan Bank.