#!/usr/bin/env python3
"""
Log File Reading Enhancement for MCP Server
Add this to your existing server to read Apache/PHP error logs
"""

import os
from pathlib import Path

# Add this method to your MoodleMCPServer class:

def read_log_file(self, log_type="error", lines=50):
    """Read various log files"""
    log_paths = {
        "error": [
            r"C:\MoodleWindowsInstaller-latest-404\server\apache\logs\error.log",
            r"C:\MoodleWindowsInstaller-latest-404\server\apache\logs\php_error.log"
        ],
        "access": [
            r"C:\MoodleWindowsInstaller-latest-404\server\apache\logs\access.log"
        ],
        "moodle": [
            r"C:\MoodleWindowsInstaller-latest-404\server\moodle\config.php"  # This would need to be parsed to find dataroot
        ]
    }
    
    if log_type not in log_paths:
        return {"error": f"Unknown log type: {log_type}. Available: {list(log_paths.keys())}"}
    
    results = []
    for log_path in log_paths[log_type]:
        if os.path.exists(log_path):
            try:
                with open(log_path, 'r', encoding='utf-8', errors='ignore') as f:
                    all_lines = f.readlines()
                    recent_lines = all_lines[-lines:] if len(all_lines) > lines else all_lines
                    
                results.append({
                    "log_path": log_path,
                    "exists": True,
                    "total_lines": len(all_lines),
                    "recent_lines": [line.strip() for line in recent_lines],
                    "file_size": os.path.getsize(log_path)
                })
            except Exception as e:
                results.append({
                    "log_path": log_path,
                    "exists": True,
                    "error": f"Could not read file: {str(e)}"
                })
        else:
            results.append({
                "log_path": log_path,
                "exists": False,
                "error": "File not found"
            })
    
    return {
        "log_type": log_type,
        "requested_lines": lines,
        "logs_checked": results
    }

# Add this to the tools/list method:
{
    "name": "read_log_file",
    "description": "Read Apache/PHP error logs",
    "inputSchema": {
        "type": "object",
        "properties": {
            "log_type": {"type": "string", "default": "error", "enum": ["error", "access", "moodle"]},
            "lines": {"type": "integer", "default": 50}
        },
    },
},

# Add this to the tools/call handler:
elif tool_name == "read_log_file":
    result = self.read_log_file(
        arguments.get("log_type", "error"),
        arguments.get("lines", 50)
    )
