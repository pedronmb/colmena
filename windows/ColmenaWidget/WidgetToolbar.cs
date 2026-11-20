namespace ColmenaWidget;

internal sealed class WidgetToolbar : Panel
{
    private readonly ToolTip toolTips = new();
    private readonly Font iconFont = new("Segoe Fluent Icons", 10);
    private readonly Button settings;
    private readonly Button minimize;
    private readonly Button close;
    private Color gripColor;

    internal WidgetToolbar(Action toggleSettings, Action minimizeWindow, Action closeWindow)
    {
        Dock = DockStyle.Top;
        Height = 34;
        DoubleBuffered = true;
        settings = AddButton("\uE713", "Configuración", toggleSettings);
        minimize = AddButton("\uE921", "Minimizar", minimizeWindow);
        close = AddButton("\uE8BB", "Cerrar", closeWindow);
        Controls.AddRange([settings, minimize, close]);
    }

    internal void ApplyPalette(WidgetPalette palette)
    {
        BackColor = palette.Background;
        gripColor = palette.Muted;
        foreach (Button button in new[] { settings, minimize, close })
        {
            button.ForeColor = palette.Text;
            button.BackColor = palette.Background;
            button.FlatAppearance.MouseOverBackColor = palette.Hover;
            button.FlatAppearance.MouseDownBackColor = palette.Hover;
        }
        Invalidate();
    }

    internal void FocusSettings() => settings.Focus();

    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);
        int scale = DeviceDpi;
        int left = 13 * scale / 96;
        int width = 17 * scale / 96;
        int gap = Math.Max(3, 4 * scale / 96);
        int middle = ClientSize.Height / 2;
        using var pen = new Pen(gripColor);
        e.Graphics.DrawLine(pen, left, middle - gap, left + width, middle - gap);
        e.Graphics.DrawLine(pen, left, middle, left + width, middle);
        e.Graphics.DrawLine(pen, left, middle + gap, left + width, middle + gap);
    }

    protected override void Dispose(bool disposing)
    {
        base.Dispose(disposing);
        if (!disposing) return;
        toolTips.Dispose();
        iconFont.Dispose();
    }

    private Button AddButton(string glyph, string label, Action action)
    {
        var button = new Button
        {
            Dock = DockStyle.Right, Width = 36, Text = glyph, AccessibleName = label, Margin = Padding.Empty,
            FlatStyle = FlatStyle.Flat, Font = iconFont, UseVisualStyleBackColor = false,
        };
        button.FlatAppearance.BorderSize = 0;
        button.Click += (_, _) => action();
        toolTips.SetToolTip(button, label);
        return button;
    }
}
