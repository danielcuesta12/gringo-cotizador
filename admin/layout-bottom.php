  </main><!-- /page-content -->
</div><!-- /main-wrapper -->

<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
