<?php
/**
 * Footer — closes main content, includes scripts
 */
?>
    </main><!-- /main-content -->
</div><!-- /app-wrapper -->

<!-- Local Scripts (no CDN dependency) -->
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/jquery-3.7.1.min.js"></script>
<script src="assets/js/jquery.dataTables.min.js"></script>
<script src="assets/js/dataTables.bootstrap5.min.js"></script>

<!-- Custom Scripts -->
<script src="assets/js/script.js"></script>

<?php if (isset($extraJs)): ?>
    <?= $extraJs ?>
<?php endif; ?>

</body>
</html>
