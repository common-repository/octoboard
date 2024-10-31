<div class="wrap">
    <h2><?php _e( 'Octoboard Settings', 'octoboard' ); ?></h2>
    <form method="post" action="options.php">
      <?php
        settings_fields( 'octoboard' );
        do_settings_sections( 'octoboard' );
        submit_button();
      ?>
    </form>
</div>