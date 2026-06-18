  </main><!-- /page-content -->
</div><!-- /main-wrapper -->

<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
<script>window.EG_GASTOS_API = '<?php echo APP_URL; ?>/api/gastos.php';</script>
<script src="<?php echo APP_URL; ?>/assets/js/combobox.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/combobox.js') ?: time(); ?>"></script>
<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
