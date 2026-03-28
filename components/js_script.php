<!-- Vendor JS Files -->
<script src="<?= HOSTURL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/simple-datatables/simple-datatables.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/apexcharts/apexcharts.min.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/chart.js/chart.umd.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/echarts/echarts.min.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/quill/quill.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/tinymce/tinymce.min.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/php-email-form/validate.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/simple-datatables/simple-datatables.js"></script>
<script src="<?= HOSTURL ?>/assets/vendor/sweet-alert/sweetalert2.all.min.js"></script>


<!-- Template Main JS File -->
<script src="<?= HOSTURL ?>/assets/js/main.js"></script>

<!-- Simple-DataTables Init for Staff Table -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Initialize Simple-DataTables
    const staffTableEl = document.querySelector("#staffTable");
    if(staffTableEl) {
        const staffDataTable = new simpleDatatables.DataTable(staffTableEl, {
            searchable: true,
            fixedHeight: false,
            perPage: 10,
            perPageSelect: [5, 10, 25, 50, 100],
            columns: [
                { select: [1, 6], sortable: false } // Disable sorting for Photo & Actions
            ],
            labels: {
                placeholder: "Search staff...",
                perPage: "{select} entries per page",
                noRows: "No matching staff found",
                info: "Showing {start} to {end} of {rows} staff"
            }
        });
    }
});
</script>
