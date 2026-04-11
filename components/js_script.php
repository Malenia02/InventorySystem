<!-- Vendor JS Files -->
<script src="/inventory_system/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/inventory_system/assets/vendor/simple-datatables/simple-datatables.js"></script>
<script src="/inventory_system/assets/vendor/apexcharts/apexcharts.min.js"></script>
<script src="/inventory_system/assets/vendor/chart.js/chart.umd.js"></script>
<script src="/inventory_system/assets/vendor/echarts/echarts.min.js"></script>
<script src="/inventory_system/assets/vendor/quill/quill.js"></script>
<script src="/inventory_system/assets/vendor/tinymce/tinymce.min.js"></script>
<script src="/inventory_system/assets/vendor/php-email-form/validate.js"></script>
<script src="/inventory_system/assets/vendor/simple-datatables/simple-datatables.js"></script>
<script src="/inventory_system/assets/vendor/sweet-alert/sweetalert2.all.min.js"></script>


<!-- Template Main JS File -->
<script src="/inventory_system/assets/js/main.js"></script>

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
