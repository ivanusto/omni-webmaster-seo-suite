<?php
/**
 * Omni_Meta_Pixel Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Meta_Pixel {
    
    private $noscript_printed = false;

    public function __construct() {
        $settings = $this->get_settings();
        
        if ( ! empty( $settings['meta_pixel_enable'] ) && ! empty( $settings['meta_pixel_id'] ) ) {
            // 在 wp_head 插入主追蹤程式碼
            add_action( 'wp_head', [ $this, 'insert_pixel_script' ], 20 );
            
            // 在 wp_body_open 插入 noscript 像素代碼
            add_action( 'wp_body_open', [ $this, 'insert_pixel_noscript' ] );
            
            // 若佈景主題較舊不支援 wp_body_open，作為後備在 wp_footer 載入
            add_action( 'wp_footer', [ $this, 'insert_pixel_noscript_fallback' ] );
        }
    }

    /**
     * 取得外掛設定值
     */
    private function get_settings() {
        $defaults = [
            'meta_pixel_enable'   => '0',
            'meta_pixel_id'       => '',
            'meta_pixel_advanced' => '0',
        ];
        return wp_parse_args( get_option( 'omni_webmaster_settings', [] ), $defaults );
    }

    /**
     * 插入 Meta Pixel 主 JavaScript 程式碼
     */
    public function insert_pixel_script() {
        $settings = $this->get_settings();
        $pixel_id = sanitize_text_field( trim( $settings['meta_pixel_id'] ) );
        
        if ( empty( $pixel_id ) ) {
            return;
        }

        $advanced_events = ! empty( $settings['meta_pixel_advanced'] ) ? true : false;
        ?>
<!-- Meta Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?php echo esc_js( $pixel_id ); ?>');
fbq('track', 'PageView');
<?php if ( $advanced_events ) : ?>
  <?php if ( is_singular() ) : 
      global $post;
      $post_id   = get_the_ID();
      $title     = get_the_title();
      $categories = get_the_category( $post_id );
      $cat_names  = [];
      if ( ! empty( $categories ) ) {
          foreach ( $categories as $cat ) {
              $cat_names[] = $cat->name;
          }
      }
      $cat_str   = implode( ', ', $cat_names );
      $post_type = get_post_type();
  ?>
fbq('track', 'ViewContent', {
    content_name: '<?php echo esc_js( $title ); ?>',
    content_category: '<?php echo esc_js( $cat_str ); ?>',
    content_ids: ['<?php echo esc_js( $post_id ); ?>'],
    content_type: '<?php echo esc_js( $post_type ); ?>'
});
  <?php elseif ( is_search() ) : 
      $search_query = get_search_query();
  ?>
fbq('track', 'Search', {
    search_string: '<?php echo esc_js( $search_query ); ?>'
});
  <?php endif; ?>
<?php endif; ?>
</script>
<!-- End Meta Pixel Code -->
        <?php
    }

    /**
     * 插入 noscript 像素代碼
     */
    public function insert_pixel_noscript() {
        if ( $this->noscript_printed ) {
            return;
        }

        $settings = $this->get_settings();
        $pixel_id = sanitize_text_field( trim( $settings['meta_pixel_id'] ) );
        
        if ( empty( $pixel_id ) ) {
            return;
        }
        ?>
<!-- Meta Pixel Code (Noscript) -->
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel_id ); ?>&ev=PageView&noscript=1"
/></noscript>
<!-- End Meta Pixel Code (Noscript) -->
        <?php
        $this->noscript_printed = true;
    }

    /**
     * noscript 後備機制 (用於不支援 wp_body_open 的主題)
     */
    public function insert_pixel_noscript_fallback() {
        $this->insert_pixel_noscript();
    }
}
