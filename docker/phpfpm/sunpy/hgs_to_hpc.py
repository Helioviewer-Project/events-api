#!/usr/bin/env python3
"""
HGS to HPC Coordinate Transformer
Reads from stdin: hgs_lat hgs_lon coord_time target_time
Writes to stdout: hpc_x hpc_y
Time format: ISO 8601 (YYYY-MM-DDTHH:MM:SS or with Z suffix)
All times are assumed to be UTC
"""

import sys
import numpy as np
from sunpy.coordinates import frames
from astropy.coordinates import SkyCoord
from astropy.time import Time
import astropy.units as u


def hgs_to_hpc(hgs_lat_deg, hgs_lon_deg, coord_time_iso, target_time_iso):
    """
    Transform HGS coordinates to HPC coordinates for a specific observation time
    
    Args:
        hgs_lat_deg: HGS latitude in degrees
        hgs_lon_deg: HGS longitude in degrees  
        coord_time_iso: Time when coordinates were measured (ISO format)
        target_time_iso: Desired observation time (ISO format)
    
    Returns:
        tuple: (hpc_x, hpc_y) in arcseconds
    """
    coord_time = Time(coord_time_iso)
    target_time = Time(target_time_iso)
    
    # Create initial coordinate in Heliographic Stonyhurst frame
    hgs_coord = SkyCoord(
        lon=hgs_lon_deg * u.deg,
        lat=hgs_lat_deg * u.deg,
        frame=frames.HeliographicStonyhurst(obstime=coord_time)
    )
    
    # Transform to Helioprojective Cartesian frame at target observation time
    # This automatically handles the solar rotation and perspective changes
    hpc_coord = hgs_coord.transform_to(
        frames.Helioprojective(
            obstime=target_time,
            observer='earth'
        )
    )
    
    return (
        float(hpc_coord.Tx.to_value(u.arcsec)),
        float(hpc_coord.Ty.to_value(u.arcsec))
    )


def main():
    """Process stdin line by line"""
    for line_num, line in enumerate(sys.stdin, 1):
        line = line.strip()
        
        # Skip empty lines and comments
        if not line or line.startswith('#'):
            continue
        
        try:
            # Parse input: expecting "hgs_lat hgs_lon coord_time target_time"
            parts = line.split(None, 3)  # Split on whitespace, max 4 parts
            
            if len(parts) < 4:
                print(f"ERROR: Line {line_num}: Need 4 values (lat lon coord_time target_time), got {len(parts)}", file=sys.stderr)
                continue
            
            # Parse coordinates and times
            hgs_lat = float(parts[0])
            hgs_lon = float(parts[1])
            coord_time = parts[2]
            target_time = parts[3]
            
            # Perform transformation
            hpc_x, hpc_y = hgs_to_hpc(hgs_lat, hgs_lon, coord_time, target_time)
            
            # Output: hpc_x hpc_y (in arcseconds)
            print(f"{hpc_x:.6f} {hpc_y:.6f}")
            sys.stdout.flush()  # Ensure immediate output for pipe usage
            
        except ValueError as e:
            print(f"ERROR: Line {line_num}: Invalid number format: {e}", file=sys.stderr)
        except Exception as e:
            print(f"ERROR: Line {line_num}: {e}", file=sys.stderr)
    
    return 0


if __name__ == "__main__":
    sys.exit(main())