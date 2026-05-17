#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_store::Builder::default().build())
        .plugin(tauri_plugin_biometric::init())
        .plugin(tauri_plugin_http::init())
        .plugin(tauri_plugin_deep_link::init())
        .setup(|app| {
            #[cfg(desktop)]
            {
                let win = app
                    .get_webview_window("main")
                    .expect("window 'main' not found");
                win.set_decorations(false)
                    .expect("failed to remove window decorations");
                win.set_fullscreen(true)
                    .expect("failed to set fullscreen");
            }
            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
