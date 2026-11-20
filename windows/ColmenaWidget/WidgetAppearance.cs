using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Win32;

namespace ColmenaWidget;

internal enum WidgetTheme { System, Light, Dark }
internal enum WidgetSize { Normal, Compact, Large, Custom }

internal readonly record struct WidgetPalette(Color Background, Color Surface, Color Text, Color Muted, Color Border, Color Hover, Color Accent)
{
    internal static WidgetPalette For(WidgetTheme theme)
    {
        if (SystemInformation.HighContrast)
            return new(SystemColors.Window, SystemColors.Control, SystemColors.WindowText, SystemColors.WindowText,
                SystemColors.WindowText, SystemColors.Control, SystemColors.HotTrack);

        bool dark = theme == WidgetTheme.Dark || (theme == WidgetTheme.System && Registry.GetValue(
            @"HKEY_CURRENT_USER\Software\Microsoft\Windows\CurrentVersion\Themes\Personalize", "AppsUseLightTheme", 1) is 0);
        return dark
            ? new(Color.FromArgb(18, 18, 18), Color.FromArgb(36, 36, 36), Color.FromArgb(236, 236, 236),
                Color.FromArgb(170, 170, 170), Color.FromArgb(63, 63, 63), Color.FromArgb(51, 51, 51), Color.FromArgb(147, 197, 253))
            : new(Color.FromArgb(247, 247, 245), Color.White, Color.FromArgb(26, 26, 26),
                Color.FromArgb(98, 98, 98), Color.FromArgb(219, 219, 215), Color.FromArgb(231, 231, 227), Color.FromArgb(29, 78, 216));
    }

    internal void ApplyTo(Control control)
    {
        control.ForeColor = Text;
        control.BackColor = control is TextBox or ComboBox or Button ? Surface : Background;
        if (control is Button button)
        {
            button.FlatStyle = FlatStyle.Flat;
            button.FlatAppearance.BorderColor = Border;
            button.FlatAppearance.MouseOverBackColor = Hover;
            button.FlatAppearance.MouseDownBackColor = Hover;
        }

        foreach (Control child in control.Controls) ApplyTo(child);
    }
}

internal static class WidgetAppearance
{
    internal const int MaximumTransparency = 60;

    internal static int ClampTransparency(int percent) => Math.Clamp(percent, 0, MaximumTransparency);

    internal static double WindowOpacity(int transparency) => (100 - ClampTransparency(transparency)) / 100.0;

    internal static Size LogicalSize(WidgetSize size) => size switch
    {
        WidgetSize.Compact => new(330, 420),
        WidgetSize.Large => new(480, 650),
        _ => new(400, 510),
    };

    internal static CoreWebView2PreferredColorScheme WebColorScheme(WidgetTheme theme) => theme switch
    {
        WidgetTheme.Light => CoreWebView2PreferredColorScheme.Light,
        WidgetTheme.Dark => CoreWebView2PreferredColorScheme.Dark,
        _ => CoreWebView2PreferredColorScheme.Auto,
    };

    internal static string DocumentThemeScript(Uri server) => $$"""
        (() => {
            if (window.top !== window) return;
            const server = new URL({{JsonSerializer.Serialize(server.AbsoluteUri)}});
            const publicRoot = new URL('public/', server);
            const paths = [server, publicRoot].flatMap(root => [
                new URL('widget.php', root).href, new URL('login.php?next=widget', root).href
            ]);
            if (!paths.includes(window.location.href)) return;

            const scheme = window.matchMedia('(prefers-color-scheme: dark)');
            const applyTheme = () => {
                if (document.documentElement) {
                    document.documentElement.dataset.theme = scheme.matches ? 'dark' : 'light';
                }
            };
            document.addEventListener('DOMContentLoaded', applyTheme, { once: true });
            scheme.addEventListener('change', applyTheme);
        })();
        """;
}
