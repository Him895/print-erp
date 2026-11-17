<?php
// ============================================================
// FILE: includes/footer.php
// Closes main content + body + html
// ============================================================
?>
        </main>

        <!-- ===== FOOTER ===== -->
        <footer class="px-4 md:px-6 py-3 border-t border-white/5 flex flex-wrap justify-between items-center text-[11px] text-slate-500 gap-2">
            <span>
                <i class="fas fa-user mr-1.5 text-slate-600"></i>
                Logged in as: <span class="text-slate-300"><?= e($_SESSION['full_name'] ?? 'User') ?></span>
                <span class="text-indigo-400 bg-indigo-500/10 px-1.5 py-0.5 rounded ml-1 uppercase tracking-wide text-[9px]"><?= e($_SESSION['role'] ?? '') ?></span>
            </span>
            <span class="flex items-center gap-2">
                <i class="fas fa-circle text-emerald-400/40 text-[5px]"></i>
                <span><?= SITE_NAME ?> · <?= SITE_VERSION ?> · <?= date('Y') ?></span>
                <i class="fas fa-circle text-emerald-400/40 text-[5px]"></i>
            </span>
        </footer>
    </div><!-- /flex-col content -->
</div><!-- /flex main -->
</body>
</html>