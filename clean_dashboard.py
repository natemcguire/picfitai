#!/usr/bin/env python3

# Script to clean up dashboard.php by removing conflicting photo management code

with open('dashboard.php', 'r') as f:
    content = f.read()

# Find the start of conflicting old functions (line 880 onwards)
lines = content.split('\n')

# Find where the new system ends and old system begins
new_system_end = None
for i, line in enumerate(lines):
    if '// Keep existing loadPhotos function for compatibility' in line:
        new_system_end = i
        break

if new_system_end:
    # Keep everything up to the new system end, then add closing script/body/html tags
    clean_lines = lines[:new_system_end]
    clean_lines.append('    </script>')
    clean_lines.append('</body>')
    clean_lines.append('</html>')

    # Write clean version
    with open('dashboard_clean.php', 'w') as f:
        f.write('\n'.join(clean_lines))

    print(f"Cleaned dashboard: removed {len(lines) - len(clean_lines)} lines of conflicting code")
    print(f"Old system started at line {new_system_end + 1}")
else:
    print("Could not find the boundary between new and old systems")