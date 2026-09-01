# Catatan migrasi

## Migrasi yang tidak boleh di-cherry-pick sebagian ke DB yang sudah berisi data

Migrasi berikut menambah kolom **NOT NULL tanpa default** ke tabel yang saat itu
masih kosong (baru dibuat di awal seed data). `config/database.php` mengaktifkan
`'strict' => true` untuk koneksi MySQL, sehingga `ALTER TABLE ... ADD COLUMN x NOT NULL`
tanpa default akan gagal dengan error 1364 ("Field doesn't have a default value")
jika dijalankan pada tabel yang sudah punya baris:

- `2022_11_06_155109_add_tickets_prefix_to_projects.php` — kolom `ticket_prefix` pada `projects`
- `2022_11_06_163226_add_code_to_tickets.php` — kolom `code` pada `tickets`
- `2022_11_06_165400_add_type_to_ticket.php` — kolom `type_id` pada `tickets`
- `2022_11_06_194728_add_priority_to_tickets.php` — kolom `priority_id` pada `tickets`

Aman untuk `migrate` dari nol (urutan penuh, tabel target masih kosong di titik
migrasi ini dijalankan), tetapi **jangan** di-cherry-pick atau di-replay sebagian
(mis. lewat `migrate:rollback` parsial lalu `migrate` ulang hanya sebagian, atau
disalin ke instalasi lain yang tabelnya sudah berisi data) tanpa mengisi kolom
tersebut lebih dulu (backfill + default sementara, baru diperketat).
