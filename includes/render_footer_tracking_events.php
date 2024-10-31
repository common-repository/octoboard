<?php if ($this->has_events_in_cookie): ?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		$.get("<?php echo esc_url(add_query_arg('octoboard_clear', 1)); ?>", function(response) {  });
	});
	</script>
<?php endif; ?>
