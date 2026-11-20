namespace ColmenaWidget;

internal sealed class WidgetPreferencesPanel : TableLayoutPanel
{
    private static readonly WidgetTheme[] Themes = [WidgetTheme.System, WidgetTheme.Light, WidgetTheme.Dark];
    private static readonly WidgetSize[] Sizes = [WidgetSize.Compact, WidgetSize.Normal, WidgetSize.Large];
    private readonly ComboBox theme = CreateSelector("Tema");
    private readonly ComboBox size = CreateSelector("Tamaño");
    private readonly CheckBox pin = new() { Text = "Siempre visible", AutoSize = true };
    private readonly TrackBar transparency = new()
    {
        Minimum = 0, Maximum = WidgetAppearance.MaximumTransparency, SmallChange = 1, LargeChange = 10,
        Dock = DockStyle.Fill, AutoSize = false, Height = 26, TickStyle = TickStyle.None,
        AccessibleName = "Transparencia de la ventana",
    };
    private readonly Label transparencyValue = new() { AutoSize = true, Anchor = AnchorStyles.Right };

    internal event EventHandler? PreferencesChanged;
    internal event EventHandler? ServerRequested;

    internal WidgetTheme Theme => Themes[theme.SelectedIndex];
    internal WidgetSize WindowSize => Sizes[size.SelectedIndex];
    internal bool AlwaysOnTop => pin.Checked;
    internal int Transparency => transparency.Value;

    internal WidgetPreferencesPanel(WidgetSettings settings)
    {
        Dock = DockStyle.Top;
        AutoSize = true;
        AutoSizeMode = AutoSizeMode.GrowAndShrink;
        ColumnCount = 2;
        ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        Padding = new(12, 4, 12, 9);
        Visible = false;

        theme.Items.AddRange(["Sistema", "Claro", "Oscuro"]);
        size.Items.AddRange(["Compacto", "Normal", "Grande"]);
        theme.SelectedIndex = Math.Max(0, Array.IndexOf(Themes, settings.Theme));
        int selectedSize = Array.IndexOf(Sizes, settings.WindowSize);
        size.SelectedIndex = selectedSize >= 0 ? selectedSize : 1;
        pin.Checked = settings.AlwaysOnTop;
        transparency.Value = WidgetAppearance.ClampTransparency(settings.Transparency);
        UpdateTransparencyValue();

        theme.SelectedIndexChanged += OnPreferencesChanged;
        size.SelectedIndexChanged += OnPreferencesChanged;
        pin.CheckedChanged += OnPreferencesChanged;
        transparency.ValueChanged += (_, args) =>
        {
            UpdateTransparencyValue();
            OnPreferencesChanged(transparency, args);
        };

        var server = new Button { Text = "Servidor…", AutoSize = true, Anchor = AnchorStyles.Right };
        server.Click += (_, _) => ServerRequested?.Invoke(this, EventArgs.Empty);
        pin.Anchor = AnchorStyles.Left;

        Controls.Add(new Label { Text = "Tema", AutoSize = true }, 0, 0);
        Controls.Add(new Label { Text = "Tamaño", AutoSize = true }, 1, 0);
        Controls.Add(theme, 0, 1);
        Controls.Add(size, 1, 1);
        Controls.Add(new Label { Text = "Transparencia", AutoSize = true, Margin = new Padding(3, 7, 3, 0) }, 0, 2);
        Controls.Add(transparencyValue, 1, 2);
        Controls.Add(transparency, 0, 3);
        SetColumnSpan(transparency, 2);
        Controls.Add(pin, 0, 4);
        Controls.Add(server, 1, 4);
    }

    internal void FocusTheme() => theme.Focus();

    private void UpdateTransparencyValue()
    {
        transparencyValue.Text = $"{Transparency} %";
        transparency.AccessibleDescription = transparencyValue.Text;
    }

    private void OnPreferencesChanged(object? sender, EventArgs e) => PreferencesChanged?.Invoke(this, e);

    private static ComboBox CreateSelector(string label)
    {
        var selector = new ComboBox
        {
            DropDownStyle = ComboBoxStyle.DropDownList, FlatStyle = FlatStyle.Flat,
            DrawMode = DrawMode.OwnerDrawFixed, Dock = DockStyle.Fill, AccessibleName = label,
        };
        selector.DrawItem += (_, args) =>
        {
            bool highlighted = (args.State & (DrawItemState.Selected | DrawItemState.ComboBoxEdit)) == DrawItemState.Selected;
            using var background = new SolidBrush(highlighted ? SystemColors.Highlight : selector.BackColor);
            args.Graphics.FillRectangle(background, args.Bounds);
            if (args.Index >= 0)
            {
                TextRenderer.DrawText(args.Graphics, selector.GetItemText(selector.Items[args.Index]), selector.Font,
                    args.Bounds, highlighted ? SystemColors.HighlightText : selector.ForeColor,
                    TextFormatFlags.VerticalCenter | TextFormatFlags.SingleLine | TextFormatFlags.EndEllipsis);
            }
            args.DrawFocusRectangle();
        };
        return selector;
    }
}
